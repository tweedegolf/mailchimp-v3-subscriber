<?php

namespace TweedeGolf\MailChimpV3Subscriber;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * MailChimp subscriber that uses the v3 api to subscribe subscribers to a given list
 * Uses Guzzle to make requests
 *
 * Class MailChimpSubscriber
 * @package Askja\Website\PublicBundle\Service
 */
class MailChimpSubscriber
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string of the form <key>-<data_center_identifier> (as presented by MailChimp when you generate a key)
     */
    private $apiKey;

    /**
     * @var string The id of the list that new subscribers are subscribed to
     */
    private $listId;

    /**
     * Root of the MailChimp v3 api, is prefixed with the data center identifier;
     * @var string
     */
    private $root = 'api.mailchimp.com/3.0/';

    /**
     * MailChimpSubscriber constructor.
     *
     * @param LoggerInterface $logger
     * @param $apiKey
     * @param $list
     */
    public function __construct(LoggerInterface $logger, $apiKey, $list = null)
    {
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->listId= $list;
    }

    /**
     * @param $listId
     */
    public function setList($listId)
    {
        $this->listId = $listId;
    }

    /**
     * @param $email
     * @param array $mergeTags
     * @return array
     * @throws \Exception
     */
    public function subscribe($email, array $mergeTags = [])
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("{$email} is not a valid email address");
        }

        if (!isset($this->listId) || empty($this->listId)) {
            throw new \Exception("List id is not set");
        }

        return $this->makeSubscribeCall($email, $mergeTags);
    }

    /**
     * @param $email
     * @param array $mergeTags
     * @return array
     */
    private function makeSubscribeCall($email, array $mergeTags = [])
    {
        $client = $this->getClient();
        $hash = md5(strtolower($email));
        $url = "lists/{$this->listId}/members/{$hash}";

        $body = [
            'email_address' => $email,
            'status_if_new' => 'subscribed',
        ];

        // submitting empty merge tags array yields bad request exception
        if ($mergeTags !== []) {
            $body['merge_fields'] = $mergeTags;
        }

        try {
            $response = $client->put($url, ['json' => $body]);
        } catch(ClientException $e) {
            $message = $e->getMessage();
            $this->logger->error("Subscribing {$email} to list {$this->listId} failed with exception message {$message}");
            return [
                'error' => true,
                'error_message' => "Bad request exception",
                'subscriber_status' => null
            ];
        }

        return $this->getResult($response);
    }

    // todo: move to constructor

    /**
     * Return Guzzle client
     * @return Client
     */
    private function getClient()
    {
        list($key, $dataCenterIdentifier) = explode('-', $this->apiKey);

        $client = new Client([
            // the 'tweedegolf' username can be anything, as per MailChimp api docs
            'base_uri' => "https://x:{$key}@{$dataCenterIdentifier}.{$this->root}",
            'headers' => [
                'Accept' => 'application/json'
            ],
            'timeout'  => 2.0,
        ]);

        return $client;
    }

    //todo: throw exception MailChimpException if

    /**
     * Parses the response to return a simplified result
     *
     * @param ResponseInterface $response
     * @return array
     */
    private function getResult(ResponseInterface $response)
    {
        // expect error
        $result = [
            'error' => true,
            'error_message' => 'An unknown error occurred when calling the MailChimp API',
            'subscriber_status' => null
        ];

        // convert to no error if we got a 200 response
        if ($response->getStatusCode() === 200) {
            $result['error'] = false;
            $result['error_message'] = null;

            //todo add try catch and check if body is not empty
            $data = json_decode($response->getBody()->getContents(), true);


            //todo return data

            // return some useful params in the result
            $result['email_address'] = $data['email_address'];
            $result['status'] = $data['status'];
            $result['unique_email_id'] = $data['unique_email_id'];
            $result['merge_fields'] = $data['merge_fields'];
        }

        return $result;
    }
}