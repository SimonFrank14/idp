<?php

namespace App\Controller\Api;

use App\Entity\ActiveDirectoryUser;
use App\Entity\User;
use App\Repository\UserRepositoryInterface;
use App\Repository\UserTypeRepositoryInterface;
use App\Request\UserAttributeRequest;
use App\Request\UserRequest;
use App\Response\ErrorResponse;
use App\Response\ListUserResponse;
use App\Response\Violation;
use App\Response\ViolationListResponse;
use App\Service\AttributePersister;
use Exception;
use JMS\Serializer\Exception\ValidationFailedException;
use JMS\Serializer\SerializerInterface;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Annotations as OA;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @Route("/api/user")
 */
class UserController extends AbstractApiController {

    private ValidatorInterface $validator;
    private UserRepositoryInterface $userRepository;
    private UserTypeRepositoryInterface $userTypeRepository;
    private AttributePersister $attributePersister;

    public function __construct(ValidatorInterface $validator, UserRepositoryInterface $userRepository, UserTypeRepositoryInterface $userTypeRepository,
                                AttributePersister $attributePersister, SerializerInterface $serializer) {
        parent::__construct($serializer);

        $this->validator = $validator;
        $this->userRepository = $userRepository;
        $this->userTypeRepository = $userTypeRepository;
        $this->attributePersister = $attributePersister;
    }

    /**
     * Returns a list of users.
     *
     * @Route("", methods={"GET"})
     * @OA\Get(operationId="api_user_list")
     * @OA\Response(
     *     response="200",
     *     description="Returns a list of users",
     *     @Model(type=ListUserResponse::class)
     * )
     * @OA\Parameter(
     *     name="offset",
     *     required=false,
     *     in="query",
     *     description="For paginated results: specifies the position of the first user to return."
     * )
     * @OA\Parameter(
     *     name="limit",
     *     required=false,
     *     in="query",
     *     description="For paginated results: specifies the number of users to return."
     * )
     */
    public function list(Request $request, UserRepositoryInterface $userRepository): Response {
        $offset = $request->query->get('offset');
        $limit = $request->query->get('limit');

        if($offset === null || !is_numeric($offset) || $offset < 0) {
            $offset = 0;
        } else {
            $offset = (int)$offset;
        }

        if($limit !== null && (!is_numeric($limit) || $limit < 0)) {
            $limit = null;
        } else {
            $limit = (int)$limit;
        }

        $uuids = $userRepository->findAllUuids($offset, $limit);
        return $this->returnJson(new ListUserResponse($uuids));
    }

    /**
     * Returns a single user.
     *
     * @Route("/{uuidOrExternalId}", methods={"GET"})
     * @OA\Get(operationId="api_user_get")
     * @OA\Response(
     *     response="200",
     *     description="Returns a single user.",
     *     @Model(type=User::class)
     * )
     * @OA\Response(
     *     response="404",
     *     description="Empty HTTP 404 response in case the user was not found."
     * )
     */
    public function user($uuidOrExternalId): Response {
        $user = $this->getUserOrThrowNotFound($uuidOrExternalId);
        return $this->returnJson($user);
    }

    /**
     * Creates a new user.
     *
     * @Route("/add", methods={"POST"})
     * @OA\Post(operationId="api_users_new")
     * @OA\RequestBody(
     *     @Model(type=UserRequest::class)
     * )
     * @OA\Response(
     *     response="201",
     *     description="User was created successfully."
     * )
     * @OA\Response(
     *     response="400",
     *     description="Validation failed.",
     *     @Model(type=ViolationListResponse::class)
     * )
     * @OA\Response(
     *     response="500",
     *     description="Server error.",
     *     @Model(type=ErrorResponse::class)
     * )
     */
    public function add(UserRequest $request): Response {
        $violations = [ ];
        $existingUser = $this->userRepository->findOneByUsername($request->getUsername());

        if($existingUser !== null) {
            $violations[] = new Violation('username', 'Username already in use.');
        }

        $existingUser = $this->userRepository->findOneByEmail($request->getEmail());

        if($existingUser !== null) {
            $violations[] = new Violation('email', 'Email address already in use.');
        }

        if(count($violations) > 0) {
            return $this->returnJson(
                new ViolationListResponse($violations),
                Response::HTTP_BAD_REQUEST
            );
        }

        $user = $this->transformRequest($request);
        $violations = $this->validator->validate($user);

        if(count($violations) > 0) {
            throw new ValidationFailedException($violations);
        }

        $this->userRepository->persist($user);

        return new Response(null, Response::HTTP_CREATED);
    }

    /**
     * Updates an existing user. Note: property username cannot be updated using this endpoint (despite the property exists in the request).
     *
     * @Route("/{uuidOrExternalId}", methods={"PATCH"})
     * @OA\Patch(operationId="api_users_update")
     * @OA\RequestBody(
     *     @Model(type=UserRequest::class)
     * )
     * @OA\Response(
     *     response="204",
     *     description="User was updated successfully."
     * )
     * @OA\Response(
     *     response="400",
     *     description="Validation failed.",
     *     @Model(type=ViolationListResponse::class)
     * )
     * @OA\Response(
     *     response="404",
     *     description="User was not found."
     * )
     * @OA\Response(
     *     response="500",
     *     description="Server error.",
     *     @Model(type=ErrorResponse::class)
     * )
     */
    public function update($uuidOrExternalId, UserRequest $request): Response {
        $user = $this->getUserOrThrowNotFound($uuidOrExternalId);

        $user = $this->transformRequest($request, $user);
        $violations = $this->validator->validate($user);

        if(count($violations) > 0) {
            throw new ValidationFailedException($violations);
        }

        $this->userRepository->persist($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Updates a users attributes. Notice: only given attributes are updated.
     *
     * @Route("/{uuidOrExternalId}/attributes", methods={"PATCH"})
     * @OA\Delete(operationId="api_users_update_attributes")
     * @OA\RequestBody(
     *     @Model(type=UserAttributeRequest::class)
     * )
     * @OA\Response(
     *     response="204",
     *     description="User was successfully removed."
     * )
     * @OA\Response(
     *     response="400",
     *     description="Validation failed.",
     *     @Model(type=ViolationListResponse::class)
     * )
     * @OA\Response(
     *     response="404",
     *     description="User was not found."
     * )
     * @OA\Response(
     *     response="500",
     *     description="Server error.",
     *     @Model(type=ErrorResponse::class)
     * )
     */
    public function updateAttributes($uuidOrExternalId, UserAttributeRequest $request): Response {
        $user = $this->getUserOrThrowNotFound($uuidOrExternalId);
        $this->attributePersister->persistUserAttributes($request->getAttributes(), $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Removes an existing user.
     *
     * @Route("/{uuidOrExternalId}", methods={"DELETE"})
     * @OA\Delete(operationId="api_user_delete")
     * @OA\Response(
     *     response="204",
     *     description="User was removed successfully."
     * )
     * @OA\Response(
     *     response="404",
     *     description="User was not found."
     * )
     * @OA\Response(
     *     response="500",
     *     description="Server error.",
     *     @Model(type=ErrorResponse::class)
     * )
     */
    public function remove($uuidOrExternalId): Response {
        $user = $this->getUserOrThrowNotFound($uuidOrExternalId);
        $this->userRepository->remove($user);
        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param UserRequest $request
     * @param User|null $user
     * @return User
     * @throws Exception
     */
    private function transformRequest(UserRequest $request, ?User $user = null): User {
        $type = $this->userTypeRepository->findOneByUuid($request->getType());

        if($type === null) {
            throw new Exception('User type not found.');
        }

        if($user === null) {
            $user = (new User());

            if(!empty($request->getPassword())) {
                $user->setPassword($request->getPassword());
                $user->setIsProvisioned(false);
            }
        }

        if(!$user instanceof ActiveDirectoryUser) {
            $user
                ->setFirstname($request->getFirstname())
                ->setLastname($request->getLastname())
                ->setEmail($request->getEmail())
                ->setExternalId($request->getExternalId())
                ->setEnabledFrom($request->getEnabledFrom())
                ->setEnabledUntil($request->getEnabledUntil())
                ->setIsActive($request->isActive());
        }

        $user
            ->setUsername($request->getUsername())
            ->setType($type)
            ->setGrade($request->getGrade());

        return $user;
    }

    private function getUserOrThrowNotFound(string $uuidOrExternalId): User {
        if(Uuid::isValid($uuidOrExternalId)) {
            $user = $this->userRepository->findOneByUuid($uuidOrExternalId);

            if ($user !== null) {
                return $user;
            }
        }

        $user = $this->userRepository->findOneByExternalId($uuidOrExternalId);

        if($user !== null) {
            return $user;
        }

        throw new NotFoundHttpException();
    }
}