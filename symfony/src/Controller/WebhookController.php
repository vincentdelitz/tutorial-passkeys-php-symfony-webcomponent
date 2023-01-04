<?php
namespace App\Controller;

use App\Repository\UserRepository;
use Corbado\Webhook\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Corbado\Webhook\Classes\Models\AuthMethodsDataResponse;
use Throwable;
use Symfony\Component\Routing\Annotation\Route;

class WebhookController extends AbstractController
{
    /**
     * @Route("/corbado-webhook", name="corbado")
     */
    public function corbado_webhook(UserRepository $userRepo, Request $request): Response
    {
        try {
            // Create new webhook instance with "webhookUsername" and "webhookPassword". Both must be
            // set in the developer panel (https://app.corbado.com) and are used to secure your
            // webhook (this one here) with basic authentication.
            $webhook = new Webhook($_ENV["WEBHOOK_USERNAME"], $_ENV["WEBHOOK_PASSWORD"]);

            // Handle authentication so your webhook is secured (basic authentication). If username
            // and/or password are invalid handleAuthentication() will send HTTP status code
            // 401 (Unauthorized) and terminate/exit execution here.
            $webhook->handleAuthentication();

            // Check if request has been made with POST. For Corbado webhooks
            // only POST is allowed/used.
            if (!$webhook->isPost()) {
                throw new Exception('Only POST is allowed');
            }

            // Get the webhook action and act accordingly. Every Corbado
            // webhook has an action.
            switch ($webhook->getAction()) {
                // Handle the "authMethods" action which basically checks
                // if a user exists on your side/in your database.
                case $webhook::ACTION_AUTH_METHODS:
                    $request = $webhook->getAuthMethodsRequest();

                    // Now check if the given user/username exists in your
                    // database and send status. Implement getUserStatus()
                    // function below.
                    $status = $this->userStatus($userRepo, $request->data->username);
                    $webhook->sendAuthMethodsResponse($status);

                    break;

                // Handle the "passwordVerify" action which basically checks
                // if the given username and password are valid.
                case $webhook::ACTION_PASSWORD_VERIFY:
                    $request = $webhook->getPasswordVerifyRequest();

                    // Now check if the given username and password is
                    // valid. Implement verifyPassword() function below.
                    if ($this->verifyPassword($userRepo, $request->data->username, $request->data->password) === true) {
                        $webhook->sendPasswordVerifyResponse(true);
                    } else {
                        $webhook->sendPasswordVerifyResponse(false);
                    }

                    break;

                default:
                    throw new Exception('Invalid action "' . $webhook->getAction() . '"');
            }
        } catch (Throwable $e) {
            // If something went wrong just return HTTP status
            // code 500. For successful requests Corbado always
            // expects HTTP status code 200. Everything else
            // will be treated as error.
            http_response_code(500);

            // We expose the full error message here. Usually you would
            // not do this (security!) but in this case Corbado is the
            // only consumer of your webhook. The error message gets
            // logged at Corbado and helps you and us debugging your
            // webhook.
            echo $e->getMessage();
            echo $e->getTraceAsString();
        }

        return new Response(status: 200, content: "OK HABAADA");
    }

    function userStatus(UserRepository $userRepo, string $username): string
    {
        $user = $userRepo->findOneBy(['email' => $username]);

        //Look up in database if $username exists and if $username is blocked/not permitted to login

        if ($user == null) {
            return AuthMethodsDataResponse::USER_NOT_EXISTS;
        }
        if ($user->isBlocked()) {
            return AuthMethodsDataResponse::USER_BLOCKED;
        }

        return AuthMethodsDataResponse::USER_EXISTS;
    }

    /**
     * Verify given username and password.
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    function verifyPassword(UserRepository $userRepo, string $username, string $password): bool
    {
        $user = $userRepo->findOneBy(['email' => $username]);
        if ($user == null || $user->getPassword() == null) {
            return false;
        }

        return $user->getPassword() == $password;

    }
}