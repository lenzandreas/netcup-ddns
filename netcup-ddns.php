<?php

// USAGE
//
// 1. Fill in all fields below.
// 2. Point whatever it is you want to run this script to the link of this
//    script. Ensure they call this script with the parameters "user", "password",
//    "domain", and "ipv4" (and/or "ipv6"). Instead of "user"/"password" you
//    can also use HTTP Basic Auth (see below).
//
//    "domain" is the fully qualified name to update, e.g. "ddns.example.com".
//    Several names can be passed separated by commas, e.g.
//    "ddns.example.com,www.example.com".
//
//    Example Fritz!Box update URL:
//    https://your.host/netcup-ddns.php?user=<username>&password=<pass>&domain=<domain>&ipv4=<ipaddr>&ipv6=<ip6addr>
//
// This script uses the netcup CloudDNS DynDNS API. It only works for domains
// that are managed through CloudDNS (the domain shows a "CloudDNS" tab in the
// CCP instead of a "DNS" tab).
//
// API ENDPOINT: https://www.customercontrolpanel.de/wsDynDns.php

////////////////////////////////////////////////////////////////////////////////
/////////////////////////////// DEFINE CONSTANTS ///////////////////////////////
////////////////////////////////////////////////////////////////////////////////

// Credentials to ensure only the correct people can run this script. Provide
// your own values here, and ensure that anyone who calls this script provides
// a "user" and a "pass" parameter with these values.
$updateUser = "your-username";
$updatePass = "your-password";

// Provide your netcup API key. This must be a key from the "API-Keys" section
// in the CCP (Master data > API) -- NOT a "Legacy API key".
$apiKey = "your-netcup-api-key";

// Only names inside these zones may be updated through this script. A name is
// allowed if it equals one of these domains or is a subdomain of one, so
// "example.com" allows "example.com", "ddns.example.com", "a.b.example.com".
// This keeps callers from touching other domains in your netcup account.
$allowedDomains = array("example.com");

// Maximum number of names accepted in one call
$maxNames = 10;

// DynDNS API endpoint
$endpoint = "https://www.customercontrolpanel.de/wsDynDns.php";
 
////////////////////////////////////////////////////////////////////////////////
///////////////////////////// AUTHENTICATE CALLER //////////////////////////////
////////////////////////////////////////////////////////////////////////////////
 
header("Content-Type: text/plain; charset=utf-8");
 
// Parameters are accepted both from the URL (GET, used by the Fritz!Box) and
// from a form-encoded POST body (e.g. curl --data).
 
// Credentials are accepted in two common ways:
//  1. HTTP Basic Auth:  https://<username>:<pass>@your.host/netcup-ddns.php?...
//  2. Parameters "user" and "password" (ownDynDNS convention), e.g.
//     ...?user=<username>&password=<pass>&...
if (isset($_SERVER['PHP_AUTH_USER'])) {
  $givenUser = (string) $_SERVER['PHP_AUTH_USER'];
  $givenPass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : "";
} else {
  $givenUser = isset($_REQUEST['user'])     ? (string) $_REQUEST['user']     : "";
  $givenPass = isset($_REQUEST['password']) ? (string) $_REQUEST['password'] : "";
}
 
if (
  $givenUser === "" || !hash_equals($updateUser, $givenUser) ||
  $givenPass === "" || !hash_equals($updatePass, $givenPass)
) {
  http_response_code(401);
  header('WWW-Authenticate: Basic realm="netcup DDNS"');
  echo "Wrong username or password.\n";
  exit(1);
}
 
echo "Successfully authenticated.\n";
 
////////////////////////////////////////////////////////////////////////////////
////////////////////////////// VALIDATE PARAMETERS /////////////////////////////
////////////////////////////////////////////////////////////////////////////////
 
/**
 * Stops the script with an HTTP 400 and a message.
 *
 * @param  string  $message  The error message
 */
function badRequest ($message) {
  http_response_code(400);
  echo $message . "\n";
  exit(1);
}
 
/**
 * Checks whether a name is one of the allowed domains or a subdomain of one.
 *
 * @param   string  $fqdn  The (lowercased) name to check
 *
 * @return  bool           True if the name may be updated
 */
function isAllowed ($fqdn) {
  global $allowedDomains;
 
  foreach ($allowedDomains as $zone) {
    $zone = strtolower($zone);
    if ($fqdn === $zone || substr($fqdn, -strlen($zone) - 1) === "." . $zone) {
      return true;
    }
  }
 
  return false;
}
 
// Read and validate the domain names
if (!isset($_REQUEST['domain']) || trim($_REQUEST['domain']) === "") {
  badRequest("Required parameter domain is missing.");
}
 
$names = array();
 
foreach (explode(",", (string) $_REQUEST['domain']) as $name) {
  // Normalize: trim spaces, lowercase, drop a trailing dot ("example.com.")
  $name = rtrim(strtolower(trim($name)), ".");
 
  if ($name === "") {
    continue;
  }
 
  if (!filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
    badRequest("\"$name\" is not a valid domain name.");
  }
 
  if (!isAllowed($name)) {
    badRequest("\"$name\" is not in the list of allowed domains.");
  }
 
  $names[$name] = true; // use keys to drop duplicates
}
 
$names = array_keys($names);
 
if (count($names) === 0) {
  badRequest("Required parameter domain is empty.");
}
 
if (count($names) > $maxNames) {
  badRequest("Too many domain names (maximum is $maxNames).");
}
 
// Read and validate the IP addresses. Empty values are ignored, so a
// Fritz!Box without IPv6 can still send "ipv6=".
$ipv4 = isset($_REQUEST['ipv4']) ? trim($_REQUEST['ipv4']) : "";
$ipv6 = isset($_REQUEST['ipv6']) ? trim($_REQUEST['ipv6']) : "";
 
if ($ipv4 !== "" && !filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
  badRequest("Parameter ipv4 is not a valid IPv4 address.");
}
 
if ($ipv6 !== "" && !filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
  badRequest("Parameter ipv6 is not a valid IPv6 address.");
}
 
if ($ipv4 === "" && $ipv6 === "") {
  badRequest("Required parameter ipv4 (or ipv6) is missing.");
}
 
////////////////////////////////////////////////////////////////////////////////
////////////////////////////// UTILITY FUNCTIONS ///////////////////////////////
////////////////////////////////////////////////////////////////////////////////
 
/**
 * Updates the A and/or AAAA record of one FQDN via the DynDNS API.
 *
 * The API expects a form-encoded POST (not JSON). We use POST rather than GET
 * so the API key does not end up in any URL or server log.
 *
 * @param   string       $fqdn  The fully qualified domain name to update
 * @param   string|null  $ipv4  The IPv4 address, or null to leave A alone
 * @param   string|null  $ipv6  The IPv6 address, or null to leave AAAA alone
 *
 * @return  bool                True on success
 */
function updateRecord ($fqdn, $ipv4, $ipv6) {
  global $endpoint;
  global $apiKey;
 
  $params = array(
    "action" => "update",
    "token"  => $apiKey,
    "fqdn"   => $fqdn
  );
 
  if ($ipv4 !== null) {
    $params["ipv4Address"] = $ipv4;
  }
  if ($ipv6 !== null) {
    $params["ipv6Address"] = $ipv6;
  }
 
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($params),
    CURLOPT_RETURNTRANSFER => true,
    // netcup redirects the endpoint (HTTP 301). Follow the redirect, and keep
    // sending a POST with the same data instead of turning it into a GET.
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_POSTREDIR      => CURL_REDIR_POST_ALL,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => "Netcup DDNS Updater Script"
  ));
 
  $response = curl_exec($ch);
 
  if ($response === false) {
    echo "$fqdn: Network error: " . curl_error($ch) . "\n";
    curl_close($ch);
    return false;
  }
 
  $httpCode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  $redirects = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
  $nextUrl   = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
  curl_close($ch);
 
  if ($redirects > 0) {
    echo "$fqdn: Note: API was redirected to $finalUrl -- consider setting \$endpoint to this URL.\n";
  }
 
  if ($httpCode >= 300 && $httpCode < 400) {
    echo "$fqdn: API still redirects (HTTP $httpCode) to: $nextUrl\n";
    return false;
  }
 
  // The API returns a JSON body with "status" and "message", also on errors
  // (HTTP 400/401/404/500).
  $result = json_decode($response);
 
  if (!is_object($result) || !isset($result->status)) {
    echo "$fqdn: Invalid API response (HTTP $httpCode).\n";
    return false;
  }
 
  $message = isset($result->message) ? $result->message : "";
 
  if ($result->status === "success") {
    echo "$fqdn: $message\n";
    return true;
  }
 
  if ($httpCode === 401) {
    $message .= " (Check that you are using a regular API key, not a Legacy API key.)";
  }
 
  echo "$fqdn: Error (HTTP $httpCode): $message\n";
  return false;
}
 
////////////////////////////////////////////////////////////////////////////////
////////////////////////////// PERFORM THE UPDATE //////////////////////////////
////////////////////////////////////////////////////////////////////////////////
 
$allSucceeded = true;
 
foreach ($names as $fqdn) {
  $ok = updateRecord(
    $fqdn,
    $ipv4 !== "" ? $ipv4 : null,
    $ipv6 !== "" ? $ipv6 : null
  );
  $allSucceeded = $allSucceeded && $ok;
}
 
if (!$allSucceeded) {
  http_response_code(500);
  exit(1);
}
 
echo "All records updated.\n";
