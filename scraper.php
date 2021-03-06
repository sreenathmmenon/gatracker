<?php
/**
 * Script for crawling through various links and fetch the Google Analytics Code from them
 * @author: Sreenath M Menon
 * @date: 05 July 2016
 */

//Preventing the error display
ini_set('display_errors', '0');

//Fixing the timeout issue
set_time_limit(0);

//place this before any script you want to calculate time
$time_start = microtime(true);

//Defining the constant values to be used in the script
define('USERAGENT', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.125 Safari/537.36');
define('START1', "ga('create',");
define('START2', "_gaq.push(['_setAccount',");
define('END1', "']);");
define('END2', "', '");
define('END3', "','");
define('END4', "\", {");
define('END5', "', \"");

//Fetching the url to be scrapped
$mainUrl = $_GET['site_url'];

//Executing the web scrapping process
executeGAscrapper($mainUrl);

/**
 * Function where the full execution take place
 * @param $mainUrl - the url from which the scrapping has to be done
 * @result - Print the result after scraping
 */
function executeGAscrapper($mainUrl) {

    //Creating a log file.
    //Check if a log file exists. If so, it will be used. Else a new file will be created
    $f = fopen("/tmp/gatracker.log", "a");
    try {

        //Validation section
        //When submit button is clicked without providing any url
        if (!$mainUrl) {
            throw new Exception('Please Input a Url to be Scrapped');
        }

        //When internet connection is not available
        if (!is_connected()) {
            throw new Exception('Unable to connect to Internet. Please check your internet connection.');
        }

        //When the webpage at the input url is not available
        if (!checkStatus($mainUrl)) {
            throw new Exception('Webpage is unavailable!');
        }

        //Fetching the contents from main page
        $mainPageContents = curlGet($mainUrl);
        
        //Get the site name from main url
        $siteName = getSiteName($mainUrl);

        // Instantiating new XPath DOM object
        $pageXpath = returnXPathObject($mainPageContents);

        //Url validation regex
        $urlValidator = '/(ftp|http|https):\/\/(\w+:{0,1}\w*@)?(\S+)(:[0-9]+)?(\/|\/([\w#!:.?+=&%@!\-\/]))?/';

        fwrite($f, "Fetching all the links" . "\n");

        //Section for fetching links from all '<a> tags
        $links = $pageXpath->query('//a');
        
        //Enter this section only if links are present
        if ($links->length > 0) {
            
            //Looping and fetching all the links and storing them in an array
            for ($i = 0; $i < $links->length; $i++) {
                
                //Fetching each link
                $url = $links->item($i)->getAttribute('href');

                //Checking for valid url
                if (preg_match($urlValidator, $url)) {
                    
                    //Removing twitter, google and facebook links via regex checks
                    $pattern = '/((http|https)\:\/\/(www\.)*(reddit|google|twitter|facebook)\.com)/';
                    preg_match($pattern, $url, $matches);

                    //Storing all the links in an array
                    if (!$matches) {
                        fwrite($f, "Adding url - ".$url." to array" . "\n");
                        $linksArray[] = $url;
                    }
                }
            }
        }

        //Condition when no links are present in the main webpage from where we started scrapping
        if (!is_array($linksArray)) {
            throw new Exception('Couldn\'t obtain a first level Links Array');
        }

        fwrite($f, "Links array is " . print_r($linksArray, true) . "\n");

        //Passing the array of links to main scrapping function
        $gDtls = multiScrapper($linksArray);

        //Passing the output of scrapping for creating the table structure for displaying in the frontend
        $toDisplay = displayData($gDtls);

        //Diplaying the table
        echo $toDisplay;
    } catch (Exception $e) {
        
        //Displaying the error messages
        echo $e->getMessage();
    }
}

/**
 * Function to scrap through an array of url' and fetch the required data
 * @param array $urls - an array of links which are to be scrapped
 * @return array $gDtls - an array with details of url and Google Analytics Id (if available)
 */
function multiScrapper($urls) {

    $f = fopen("/tmp/gatracker.log", "a");

    //Creating an array of start conditions
    $startArray = array(START1, START2);

    //Initialisation section
    $ch      = array();
    $results = array();
    $index   = 0;

    //Curl initialisation
    $mh = curl_multi_init();
    
    //Looping through each link
    foreach ($urls as $key => $val) {
        
        fwrite($f, "Url is ".$val."\n");
 
        //Individual initialization and setting the conditions
        $ch[$key] = curl_init();
        curl_setopt($ch[$key], CURLOPT_URL, $val);
        curl_setopt($ch[$key], CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch[$key], CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch[$key], CURLOPT_USERAGENT, USERAGENT); // Setting useragentss
        curl_multi_add_handle($mh, $ch[$key]);
    }
    $running = null;
    do {
        
        //Curl Execution section
        curl_multi_exec($mh, $running);
    } while ($running > 0);

    //Get content and remove handles.
    foreach ($ch as $key => $val) {
        
        //Fetching the contents
        $results[$key] = curl_multi_getcontent($val);
        curl_multi_remove_handle($mh, $val);
    }

    //Closing the connection
    curl_multi_close($mh);

    //Looping through each page content for next level scrapping
    foreach ($results as $data) {

        if (stripos($data, START1) > 0) {
            $endArray = array(END4, END5, END2, END3);
        } else if (stripos($data, START2) > 0) {
            $endArray = array(END1);
        }
        
        //Fetching the start position and length
        $start    = strposa($data, $startArray);
        $startPos = $start['pos'];
        $startLen = $start['subVal'];

        // If $start string is not found, we will set the analytics Id as NIL
        if (!$startPos > 0) {
            $finalExtract = 'nil';         
        } else {

            //Using the start condition we, extract a part that contains our data from original page content
            $firstExtract = substr($data, $startLen, $startPos);
            
            //Setting a regex similar to Google Anlytics Id
            $regex = '/UA-([a-zA-Z0-9-])+/';

            //Fetching the end position and length
            $end = strposa($firstExtract, $endArray, 0);
            $endPos = $end['pos'];
            $endLen = $end['subVal'];
            
            fwrite($f, "URL is ".$urls[$index]."\n");

            //Extract the Google Analytics Id
            $finalExtract = substr($firstExtract, 0, $endPos);
            
            //Regex comparison to ensure that we get only the Google Analytics Id in our output
            preg_match($regex, $finalExtract, $matches);
            $finalExtract = $matches[0];
            
            //Removing quotes(if present) from our output
            $finalExtract = unQuote($finalExtract);
            fwrite($f, "Final extract is ".$finalExtract."\n");
        }

        //Setting an array for storing each link and the Analytics Id (if present) in that link
        $gDtls[] = array(
            "url" => $urls[$index],
            "analyticsId" => $finalExtract
        );
        
        //Incrementing the Index
        //This is used for fetching the links
        $index++;
    }

    //Return the array containing url and it's Google Analytics ID
    return $gDtls;
}

/**
 * Function to search for presence of any of the matching words from an array in a string or text file
 * @param type $haystack - string or content in which the search is to be performed
 * @param type $needle   - array containing the words which are to be searched
 * @param type $offset   - setting the position for searching
 * @return an array with the position and length
 */
function strposa($haystack, $needle, $offset = 0) {

    //If search value is not in an array format, we will convert it into an array
    if (!is_array($needle)) {
        $needle = array($needle);
    }

    //Looping through each search value
    foreach ($needle as $query) {

        //If a matching set is found, we will use it
        if (strpos($haystack, $query, $offset) > 1) {

            //Position and length
            $pos    = stripos($haystack, $query);
            $subLen = $pos + strlen($query);

            //Returning in an array
            return array(
                'pos'    => $pos, 
                'subVal' => $subLen
            );
        }
    }
    return false;
}

/**
 * Function to check if the internet connection is available or not
 * @param null
 * @return boolean true if connection is available
 */
function is_connected() {

    //website, port  (try 80 or 443)
    $connected = @fsockopen("www.google.com", 80);

    //Check if internet connection is available
    if ($connected) {

        //Setting the value as true since connection is available
        $is_conn = true; 
        fclose($connected);
    } else {
        
        //Connection failure
        $is_conn = false; 
    }
    
    //Return the details
    return $is_conn;
}

/**
 * Function to make GET request using cURL
 * @param $url- the url which is to be scrapped
 * @return type Description
 * NOTE - This function is not used in this script anymore as an alternate Multi Curl method has been found to be faster
 */
function curlGet($url) {

    //$useragent = 'Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10.5;en-US; rv:1.9.2.3) Gecko/20100401 Firefox/3.6.3'; // Setting user agent of a popular browser
    $useragent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/44.0.2403.125 Safari/537.36';
    
    //Initialising cURL session
    $ch = curl_init();
    
    //Setting curl options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    
    //Setting useragent
    curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
    curl_setopt($ch, CURLOPT_URL, $url);
    
    // Executing curl session
    $results = curl_exec($ch); 

    //Closing curl session
    curl_close($ch);
    
    //Return the results
    return $results;
}

/**
 * Function to find the domain from url
 * @param string $url - the link to be scrapped
 * @return the host domain after the parsing
 */
function getDomain($url) {

    /* Validating url based on the filter */
    if (filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_HOST_REQUIRED) === FALSE) {
        return false;
    }

    /* get the url parts to find the domain section */
    $parts = parse_url($url);

    /* return the host domain which is the required result */
    return $parts ['scheme'] . '://' . $parts ['host'];
}

/**
 * Function to fetch the original url from short links links t.co
 * @url    - the short link which is to be analyzed
 * @return - the original url
 */
function fetchOriginalUrl($url) {
    
    //Url Initialization
    $ch = curl_init($url);
    
    //Setting all options
    curl_setopt_array($ch, array(
        
        //Main part
        CURLOPT_FOLLOWLOCATION => TRUE,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_SSL_VERIFYHOST => FALSE,
        CURLOPT_SSL_VERIFYPEER => FALSE,
        
        //Do the request without getting the body
        CURLOPT_NOBODY => TRUE
    ));

    //Execution section
    curl_exec($ch);
    
    //Return the original url
    return curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
}

/**
 * Function to return the XPATH object
 * @param $item - the url whose contents are to be fetched
 * @return \DOMXPath
 */
function returnXPathObject($item) {
    
    //Instantiating a new DomDocument object
    $xmlPageDom = new DomDocument();
    
    //Loading the HTML from downloaded page
    //Also, we are suppressing the errors(if any)
    @$xmlPageDom->loadHTML($item);
    
    //Instantiating new XPath DOM object
    $xmlPageXPath = new DOMXPath($xmlPageDom); 
    
    //Returning XPath object
    return $xmlPageXPath;
}

/**
 * Function to print an array for debugging purpose
 * @param $d - an array to be printed
 * @return   - print the array
 */
function debug($d) {
    return '<pre>' . print_r($d, true) . '</pre>';
}

/**
 * Function to display the data in a table format
 * @param type $gDtls - an array containing the url and the google analytics id
 * @return $html - a string containing the html data to be displayed in the frontend
 */
function displayData($gDtls) {

    //Initializing the values
    $num  = 0;
    $html = '
    <div class="table-responsive results-table">
    <table class="table table-striped">
        <thead>
            <tr>
                <th>No.</th>
                <th>Scrapped Url</th>
                <th>Google Analytics ID</th>
            </tr>
        </thead>
        <tbody>';

    //Displaying each url and analytics id as separate rows
    for ($i = 0; $i < count($gDtls); $i++) {
        $num++;
        $html .= "<tr>
                    <td>" . $num . "</td>
                    <td>" . $gDtls[$i]['url'] . "</td>
                    <td>" . $gDtls[$i]['analyticsId'] . "</td>
                </tr>";
    }
    $html .= '</tbody>
                  </table>
                  </div>';
    
    //Returning the html data
    return $html;
}

/**
 * Function to find the site name from url
 * @param string $url - the link to be scrapped
 * @return the site name after the parsing
 */
function getSiteName($url) {

    //Append http is ftp or http or http is not present
    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
        $url = "http://" . $url;
    }

    //Parse the url and obtain it's parts
    $parts = parse_url($url);
   
    //Fetch the host part ( ection after http:// )
    $mainDomain = $parts['host'];
    
    //Append www at the start if it's not present
    if (!preg_match("~^www\.~i", $mainDomain)) {
        $mainDomain = "www." . $mainDomain;
    }

    //Explode and tore the parts in an array
    $domParts = explode('.', $mainDomain);
    
    //Fetch the main sitename and return it
    $siteName = $domParts[1];
    return $siteName;
}

/**
 * Function to remove quotes from a string
 * @param type $value   - the string from which, the quotes are to be removed
 * @return type $value  - the string after removing the quotes
 */
function unQuote($value) {

    //Replacing the single and double quotes
    $value = str_replace(array("'", "\""), '', $value);
    return $value;
}

/**
 * Function to check if webpage is available
 * @param type $url - the link which is to be checked for availability
 * @return boolean - true, if webpage is available
 */
function checkStatus($url) {

    // initializes curl session
    $ch = curl_init();

    //Sets the URL to fetch
    curl_setopt($ch, CURLOPT_URL, $url);

    //Sets the content of the User-Agent header
    curl_setopt($ch, CURLOPT_USERAGENT, USERAGENT);

    //Make sure you only check the header - taken from the answer above
    curl_setopt($ch, CURLOPT_NOBODY, true);

    //Follow "Location: " redirects
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    //Return the transfer as a string
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    //Disable output verbose information
    curl_setopt($ch, CURLOPT_VERBOSE, false);

    //Max number of seconds to allow cURL function to execute
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    //Execute
    curl_exec($ch);

    //Get HTTP response code
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    //Return true if webpage is available
    if ($httpcode >= 200 && $httpcode < 400)
        return true;
    else
        return false;
}

// Display Script End time
$time_end = microtime(true);

//dividing with 60 will give the execution time in minutes other wise seconds
$execution_time = ($time_end - $time_start) / 60;

//execution time of the script
echo '<div class="execution_time"><b>Total Execution Time:</b> ' . $execution_time . ' Mins</div>';
