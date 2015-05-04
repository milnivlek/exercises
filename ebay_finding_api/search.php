<?php

/*
===============================================================================

Web service that finds eBay items matching a given search query and returns:

(a) The average price of the matching items
(b) Information about the highest-priced item

This service should be invoked with two parameters:

	/ebay/search?searchString={searchString}&numItems={numItems}

where:
	{searchString} = eBay search query string
	{numItems}     = Maximum number of search results to examine (up to 100)

===============================================================================
*/

//=============================================================================
// Application parameters and configuration settings.
//=============================================================================

define('EBAY_API_ENDPOINT', 'http://svcs.ebay.com/services/search/FindingService/v1');
define('EBAY_APP_NAME', 'KelvinLi-1b0a-475e-b9dd-398edbc18b67');
define('EBAY_API_VERSION', '1.0.0');

//=============================================================================
// Main program logic begins here.
//=============================================================================

// Parse and validate the inputs.

header('Content-Type: application/json');

list($searchString, $numItems, $inputError) = parseInputs();
if ($inputError) {
	echo constructErrorResponse($inputError);
	exit;
}

// Use the eBay Finding API to retrieve the items matching the search query.

$items = findEbayItems($searchString, $numItems);
if (!count($items)) {
	echo constructErrorResponse('No items matched the given search string');
	exit;
}

// Among the eBay search results, determine the highest priced item and the
// average item price.

$highestPricedItem = getHighestPriceItem($items);
list($averagePrice, $currency) = getAveragePrice($items);

// Construct and return the response.

echo constructResponse($highestPricedItem, $averagePrice, $currency);
exit;


/**
 * Parse and validate the service inputs.
 *
 * @return array	Array containing the search string, number of items, 
 *		and input error message, in that order
 */
function parseInputs() {
	
	$searchString = $_GET['searchString'];
	$numItems = $_GET['numItems'];

	if (!$searchString) {
		return array(null, null, 'Parameter "searchString" was not specified');
		
	} else if (!$numItems) {
		return array(null, null, 'Parameter "numItems" was not specified');
		
	} else if ($numItems < 1 || $numItems > 100) {
		return array(null, null, 'Parameter "numItems" must be between 1 and 100 (inclusive)');
		
	} else {
		return array($searchString, $numItems, null);
	}
}


/**
 * Construct and returns the JSON-encoded response from the
 * given parameter values.
 *
 * @param object $highestItem   Highest priced item
 * @param double $averagePrice  Average item price
 * @param string $currency      Currency of the items
 */
function constructResponse($highestItem, $averagePrice, $currency) {
	
	$highestItemCategory = $highestItem->primaryCategory[0];
	$highestItemPrice = $highestItem->sellingStatus[0]->convertedCurrentPrice[0];
	
	return json_encode(array(
		'averagePrice' => array(
			'amount' => $averagePrice,
			'currencyId' => $currency
		),
		'itemWithHighestPrice' => array(
			'itemId' => $highestItem->itemId[0],
			'title' => $highestItem->title[0],
			'primaryCategoryId' => $highestItemCategory->categoryId[0],
			'primaryCategoryName' => $highestItemCategory->categoryName[0],
			'price' => array(
				'amount' => $highestItemPrice->{'__value__'},
				'currencyId' => $highestItemPrice->{'@currencyId'}
			)
		)
	));
}


/**
 * Constructs an error response with the given error message.
 *
 * @param string $errorMsg  Error message
 */
function constructErrorResponse($errorMsg) {
	return json_encode(array(
		'error' => $errorMsg
	));
}


/**
 * Determines the average price among the given eBay items.
 *
 * @param array	$items   eBay items
 * @return array  Average price and currency
 */
function getAveragePrice($items) {
	
	$count = 0;
	$totalPrice = 0;
	$currency = null;
	
	// Iterate through the items, totaling up their price amounts.
	// Also grab the price currency ID.
	
	foreach ($items as $item) {
		++$count;
		$price = $item->sellingStatus[0]->convertedCurrentPrice[0];
		$totalPrice += (double) $price->{'__value__'};
		$currency = $price->{'@currencyId'};
	}
	
	// Compute the average price (arithmetic mean of the item prices)
	// and return it along with the price currency ID.
	
	$averagePrice = round($totalPrice / $count, 2);
	return array($averagePrice, $currency);
}


/**
 * Determines the highest-priced item among the given eBay items.
 *
 * @param array $items		eBay items
 * @return object Highest-priced item
 */
function getHighestPriceItem($items) {
	
	$highestPrice = -1.0;
	$highestItem = null;
	
	// Iterate through the items, checking whether each one has a
	// higher price than the highest-priced item found so far.
	
	foreach ($items as $item) {
		$price = (double) $item->sellingStatus[0]->convertedCurrentPrice[0]->{'__value__'};
		if ($price > $highestPrice) {
			$highestItem = $item;
			$highestPrice = $price;
		}
	}
	
	// Return the highest-priced item.
	
	return $highestItem;
}


/**
 * Invokes the eBay Finding API to obtain the eBay items matching
 * the given search query string, up to the given maximum number of
 * items.
 *
 * @param string $searchString	Search query string
 * @param integer $maxItemCount	Maximum number of items to return
 * @return array  eBay items
 */
function findEbayItems($searchString, $maxItemCount) {

	// Construct the request data object.

	$request = array(
		'jsonns.xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
		'jsonns.xs' => 'http://www.w3.org/2001/XMLSchema',
		'jsonns.tns' => 'http://www.ebay.com/marketplace/search/v1/services',
		'tns.findItemsByKeywordsRequest'  => array(
			'keywords' => $searchString,
			'itemFilter' => array(array(
				'name' => 'ListingType',
				'value' => array(
					'AuctionWithBIN',
					'FixedPrice',
					'StoreInventory'
				)
			)),
			'paginationInput' => array( 
				'entriesPerPage' => $maxItemCount,
				'pageNumber' => 1
			)
		)
	);
	
	// Prepare the HTTP request.

	$curl = curl_init();
	curl_setopt_array($curl, array(
		CURLOPT_POST => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_URL => EBAY_API_ENDPOINT . '?' . http_build_query(array(
			'OPERATION-NAME' => 'findItemsByKeywords',
			'SERVICE-VERSION' =>  EBAY_API_VERSION,
			'SECURITY-APPNAME' => EBAY_APP_NAME,
			' X-EBAY-SOA-GLOBAL-ID' => 'EBAY-MAIN',
			'X-EBAY-SOA-REQUEST-DATA-FORMAT' => 'JSON',
			'X-EBAY-SOA-RESPONSE-DATA-FORMAT' => 'JSON',
		)),
		CURLOPT_HTTPHEADER => array(
			'Content-Type' => 'application/json'
		),
		CURLOPT_POSTFIELDS => json_encode($request)
	));

	// Make the HTTP call.
	
	$response = curl_exec($curl);
	curl_close($curl);
	
	// If no valid response was returned, treat it as if no items were returned.
	// Otherwise, decode the response string and return the list of matching items.
	
	return !$response
		? array()
		: json_decode($response)->findItemsByKeywordsResponse[0]->searchResult[0]->item;
}

?>