<?php

namespace TweedeGolf\MailChimpV3Subscriber;

use Behat\Mink\Exception\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TweedeGolf\MailChimpV3Subscriber\Exception\MailChimpSubscribeException;

/**
 * MailChimp subscriber that uses the v3 api to subscribe subscribers to a given list
 * Uses Guzzle to make requests.
 *
 * Class MailChimpSubscriber
 */
class MailChimpSubscriber
{
    /**
     * @string
     */
    const API_ROOT = 'api.mailchimp.com/3.0/';

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
        $this->listId = $list;
        $this->client = $this->initClient($apiKey);
    }

    /**
     * Return Guzzle client.
     *
     * @param $apiKey
     *
     * @return Client
     */
    private function initClient($apiKey)
    {
        $root = self::API_ROOT;
        list($key, $dataCenterIdentifier) = explode('-', $apiKey);

        $client = new Client([
            'base_url' => "https://{$dataCenterIdentifier}.{$root}",
            'defaults' => [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => "Basic {$key}",
                ],
                'timeout' => 2.0
            ]
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
     *
     * @return bool
     */
    public function isSubscribed($email)
    {
        try {
            $info = $this->getMemberInfo($email);

            return isset($info['status']) && $info['status'] === 'subscribed';
        } catch (ClientException $e) {
            // exception is already logged in getMemberInfo call
            return false;
        }
    }

    /**
     * @param $email
     *
     * @return array
     */
    public function unSubscribe($email)
    {
        return $this->update($email, [], 'unsubscribed');
    }

    /**
     * @param $email
     * @param array $mergeFields
     *
     * @return array
     */
    public function subscribe($email, array $mergeFields = [])
    {
        return $this->update($email, $mergeFields);
    }

    /**
     * @param $email
     * @param array  $mergeFields
     * @param string $status
     *
     * @return array
     *
     * @throws MailChimpSubscribeException
     * @throws \Exception
     */
    public function update($email, array $mergeFields = [], $status = 'subscribed')
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MailChimpSubscribeException("{$email} is not a valid email address");
        }

        if (!isset($this->listId) || empty($this->listId)) {
            throw new MailChimpSubscribeException('MailChimp list id is not set');
        }

        $hash = md5(strtolower($email));
        $url = "lists/{$this->listId}/members/{$hash}";

        $body = [
            'email_address' => $email,
            'status' => $status,
        ];

        // submitting empty merge fields array yields bad request exception
        if (is_array($mergeFields) && count($mergeFields) > 0) {
            $body['merge_fields'] = $mergeFields;
        }

        try {
            $response = $this->client->put($url, ['json' => $body]);

            return $this->decodeMailChimpResponse($response);
        } catch (ClientException $e) {
            $message = "Subscribing {$email} to list {$this->listId} failed: {$e->getMessage()}";
            $this->logger->error($message);

            // convert to subscribe exception
            throw new MailChimpSubscribeException($message);
        }
    }

    /**
     * @param $email
     *
     * @return mixed
     *
     * @throws MailChimpSubscribeException
     */
    public function getMemberInfo($email)
    {
        $hash = md5(strtolower($email));
        $url = "lists/{$this->listId}/members/{$hash}";

        try {
            $response = $this->client->get($url);

            // return empty result when user was not found
            if ($response->getStatusCode() === 404) {
                return [];
            }

            return $this->decodeMailChimpResponse($response);
        } catch (ClientException $e) {
            $message = "Obtaining member info for {$email} from list {$this->listId} failed: {$e->getMessage()}";
            $this->logger->error($message);

            throw new MailChimpSubscribeException($message);
        }
    }

    /**
     * @param ResponseInterface $response
     *
     * @return mixed
     *
     * @throws MailChimpSubscribeException
     */
    private function decodeMailChimpResponse(ResponseInterface $response)
    {
        if ($response->getStatusCode() === 200) {
            if (!is_object($response->getBody())) {
                throw new MailChimpSubscribeException('Could not get MailChimp response body');
            }

            $contents = $response->getBody()->getContents();

            if (empty($contents)) {
                throw new MailChimpSubscribeException('Empty MailChimp response');
            }

            try {
                $data = json_decode($contents, true);
            } catch (Exception $e) {
                throw new MailChimpSubscribeException('Could not parse MailChimp JSON response');
            }

            return $data;
        }

        throw new MailChimpSubscribeException("Error with MailChimp response: {$response->getStatusCode()}");
    }
}
