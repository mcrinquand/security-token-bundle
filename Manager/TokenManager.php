<?php

namespace Yokai\SecurityTokenBundle\Manager;

use DateTime;
use Yokai\SecurityTokenBundle\Entity\Token;
use Yokai\SecurityTokenBundle\EventDispatcher;
use Yokai\SecurityTokenBundle\Exception\TokenExpiredException;
use Yokai\SecurityTokenBundle\Exception\TokenNotFoundException;
use Yokai\SecurityTokenBundle\Exception\TokenConsumedException;
use Yokai\SecurityTokenBundle\Factory\TokenFactoryInterface;
use Yokai\SecurityTokenBundle\InformationGuesser\InformationGuesserInterface;
use Yokai\SecurityTokenBundle\Repository\TokenRepositoryInterface;

/**
 * @author Yann Eugoné <eugone.yann@gmail.com>
 */
class TokenManager implements TokenManagerInterface
{
    /**
     * @var TokenFactoryInterface
     */
    private $factory;

    /**
     * @var TokenRepositoryInterface
     */
    private $repository;

    /**
     * @var InformationGuesserInterface
     */
    private $informationGuesser;

    /**
     * @var UserManagerInterface
     */
    private $userManager;

    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    /**
     * @param TokenFactoryInterface       $factory            The token factory
     * @param TokenRepositoryInterface    $repository         The token repository
     * @param InformationGuesserInterface $informationGuesser The information guesser
     * @param UserManagerInterface        $userManager        The user manager
     * @param EventDispatcher             $eventDispatcher    The event dispatcher
     */
    public function __construct(
        TokenFactoryInterface $factory,
        TokenRepositoryInterface $repository,
        InformationGuesserInterface $informationGuesser,
        UserManagerInterface $userManager,
        EventDispatcher $eventDispatcher
    ) {
        $this->factory = $factory;
        $this->repository = $repository;
        $this->informationGuesser = $informationGuesser;
        $this->userManager = $userManager;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @inheritdoc
     */
    public function get($purpose, $value)
    {
        try {
            $token = $this->repository->get($value, $purpose);
        } catch (TokenNotFoundException $exception) {
            $this->eventDispatcher->tokenNotFound($purpose, $value);

            throw $exception;
        } catch (TokenExpiredException $exception) {
            $this->eventDispatcher->tokenExpired($purpose, $value);

            throw $exception;
        } catch (TokenConsumedException $exception) {
            $this->eventDispatcher->tokenAlreadyConsumed($purpose, $value);

            throw $exception;
        }

        $this->eventDispatcher->tokenRetrieved($token);

        return $token;
    }

    /**
     * @inheritdoc
     */
    public function create($purpose, $user, array $payload = [])
    {
        $event = $this->eventDispatcher->createToken($purpose, $user, $payload);

        $token = $this->factory->create($user, $purpose, $event->getPayload());

        $this->repository->create($token);

        $this->eventDispatcher->tokenCreated($token);

        return $token;
    }

    /**
     * @inheritdoc
     */
    public function setUsed(Token $token, DateTime $at = null)
    {
        @trigger_error(
            'The '.__METHOD__
            .' method is deprecated since version 2.2 and will be removed in 3.0. Use the consume() method instead.',
            E_USER_DEPRECATED
        );

        $this->consume($token, $at);
    }

    /**
     * @inheritDoc
     */
    public function consume(Token $token, DateTime $at = null)
    {
        $event = $this->eventDispatcher->consumeToken($token, $at, $this->informationGuesser->get());

        $token->consume($event->getInformation(), $at);

        $this->repository->update($token);

        $this->eventDispatcher->tokenConsumed($token);
        if ($token->isConsumed()) {
            $this->eventDispatcher->tokenTotallyConsumed($token);
        }
    }

    /**
     * @inheritdoc
     */
    public function getUser(Token $token)
    {
        return $this->userManager->get($token->getUserClass(), $token->getUserId());
    }
}
