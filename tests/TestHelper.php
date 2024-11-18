<?php

namespace BackblazeB2\Tests;

use BackblazeB2\Http\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

trait TestHelper
{
    protected function buildGuzzleFromResponses(array $responses, $history = null)
    {
        $mock = new MockHandler($responses);
        $handler = new HandlerStack($mock);

        if ($history) {
            $handler->push($history);
        }

        // Add debugging middleware
        $handler->push(Middleware::mapResponse(function ($response) {
            //echo "\nDebug - Response body: " . $response->getBody() . "\n";
            //echo "Debug - Request target: " . $response->getStatusCode() . "\n";
            return $response;
        }));

        return new HttpClient(['handler' => $handler]);
    }

    protected function buildResponseFromStub($statusCode, array $headers, $responseFile)
    {
        $content = file_get_contents(dirname(__FILE__).'/responses/'.$responseFile);
        // Trim any trailing characters
        $content = trim($content);

        // For download_content which isn't JSON
        if (!str_ends_with($responseFile, '.json')) {
            return new Response($statusCode, $headers, $content);
        }

        // Validate JSON
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf(
                'Invalid JSON in response stub %s: %s',
                $responseFile,
                json_last_error_msg()
            ));
        }

        // Re-encode to ensure clean JSON
        $content = json_encode($decoded);

        return new Response($statusCode, $headers, $content);
    }
}
