<?php

use Flagship\Apis\Exceptions\ApiException;
use Flagship\Shipping\Collections\RatesCollection;
use Flagship\Shipping\Exceptions\QuoteException;
use Flagship\Shipping\Requests\QuoteRequest;

class FlagshipDetailedQuoteRequest extends QuoteRequest
{
    /** @var \stdClass|null */
    protected $rawResponse;

    public function executeWithDetails() : RatesCollection
    {
        try {
            $responseArray = $this->api_request(
                $this->url,
                $this->payload,
                $this->token,
                'POST',
                10,
                $this->flagshipFor,
                $this->version
            );

            $this->rawResponse = $responseArray['response'] ?? null;

            $responseObject = count((array)($responseArray['response'] ?? [])) === 0 ?
                [] :
                $responseArray['response']->content;

            $newQuotes = new RatesCollection();
            $newQuotes->importRates($responseObject);
            $this->responseCode = $responseArray['httpcode'];

            return $newQuotes;
        } catch (ApiException $e) {
            throw new QuoteException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function getRawResponse()
    {
        return $this->rawResponse;
    }
}
