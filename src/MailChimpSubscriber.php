<?php

namespace TweedeGolf\MailChimpV3Subscriber;

use Behat\Mink\Exception\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TweedeGolf\MailChimpV3Subscriber\Exception\MemberInfoException;
use TweedeGolf\MailChimpV3Subscriber\Exception\SubscribeException;

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
     * @var Client
     */
    private $client;

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
        $this->client = $this->initClient($apiKey);
    }

    //todo: use http auth header in stead of in url

    /**
     * Return Guzzle client
     * @param $apiKey
     * @return Client
     */
    private function initClient($apiKey)
    {
        $root = 'api.mailchimp.com/3.0/';
        list($key, $dataCenterIdentifier) = explode('-', $apiKey);

        $client = new Client([
            // the 'x' username can be anything, as per MailChimp api docs
            'base_uri' => "https://x:{$key}@{$dataCenterIdentifier}.{$root}",
            'headers' => [
                'Accept' => 'application/json'
            ],
            'timeout'  => 2.0,
        ]);

        return $client;
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
     * @param array $mergeFields
     * @return array
     * @throws \Exception
     */
    public function subscribe($email, array $mergeFields = [])
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("{$email} is not a valid email address");
        }

        if (!isset($this->listId) || empty($this->listId)) {
            throw new \Exception("List id is not set");
        }

        $hash = md5(strtolower($email));
        $url = "lists/{$this->listId}/members/{$hash}";

        $body = [
            'email_address' => $email,
            'status_if_new' => 'subscribed',
        ];

        // submitting empty merge fields array yields bad request exception
        if ($mergeFields !== []) {
            $body['merge_fields'] = $mergeFields;
        }

        try {
            //todo: change to post request?
            $response = $this->client->put($url, ['json' => $body]);
        } catch(ClientException $e) {
            $message = $e->getMessage();
            $this->logger->error("Subscribing email {$email} to list {$this->listId} failed with exception message {$message}");

            // convert to subscribe exception
            throw new SubscribeException();
        }

        return $this->getSubscriptionResult($response);
    }

    /**
     * Parses the response to return a simplified result
     *
     * @param ResponseInterface $response
     * @return array
     * @throws SubscribeException
     */
    private function getSubscriptionResult(ResponseInterface $response)
    {
        if ($response->getStatusCode() === 200) {

            if (!is_object($response->getBody())) {
                throw new SubscribeException();
            }

            $contents = $response->getBody()->getContents();

            if (empty($contents)) {
                throw new SubscribeException();
            }

            try {
                $data = json_decode($contents, true);
            } catch (Exception $e) {
                throw new SubscribeException();
            }

            return $data;
        }

        throw new SubscribeException();
    }

    /**
     * @param $email
     * @return mixed
     * @throws MemberInfoException
     */
    public function getMemberInfo($email)
    {
        $hash = md5(strtolower($email));
        $url = "lists/{$this->listId}/members/{$hash}";

        try {
            $response = $this->client->get($url);
        } catch (ClientException $e) {
            $message = $e->getMessage();
            $this->logger->error("Obtaining member info for email {$email} from list {$this->listId} failed with exception message {$message}");

            throw new MemberInfoException();
        }

        return $this->getMemberInfoResult($response);
    }

    /**
     * @param ResponseInterface $response
     * @return mixed
     * @throws MemberInfoException
     */
    private function getMemberInfoResult(ResponseInterface $response)
    {
        if ($response->getStatusCode() === 200) {

            if (!is_object($response->getBody())) {
                throw new MemberInfoException();
            }

            $contents = $response->getBody()->getContents();

            if (empty($contents)) {
                throw new MemberInfoException();
            }

            try {
                $data = json_decode($contents, true);
            } catch (Exception $e) {
                throw new MemberInfoException();
            }

            return $data;
        }

        throw new MemberInfoException();
    }



}