#!/usr/bin/env php
<?php

fwrite(STDERR, "COUCH DB DUMPER | version: 1.0.0" . PHP_EOL);
fwrite(STDERR, "(c) Copyright 2013, Anton Bondar <anton@zebooka.com> http://zebooka.com/soft/LICENSE/" . PHP_EOL . PHP_EOL);

$help = <<<HELP
   This tool dumps available documents it can find using _all_docs request to CouchDB.
   Dump format is compatible with _bulk_docs feature in CouchDB.

OPTIONS:
   -h                 Display this help message.
   -e                 Turn php error reporting ON.
   -H <HOSTNAME>      Hostname or IP of CouchDB server (default: 'localhost').
   -p <PORT>          Port of CouchDB server (default: 5984).
   -d <DATABASE>      Database to dump.

USAGE:
   {$_SERVER['argv'][0]} -H localhost -p 5984 -d test > dump.json
HELP;

$params = parseParameters($_SERVER['argv'], array('H', 'p', 'd'));
error_reporting(!empty($params['e']) ? -1 : 0);
defined('JSON_UNESCAPED_SLASHES') || define('JSON_UNESCAPED_SLASHES', '0');
defined('JSON_UNESCAPED_UNICODE') || define('JSON_UNESCAPED_UNICODE', '0');

if (isset($params['h'])) {
    fwrite(STDERR, $help . PHP_EOL);
    exit(1);
}

$host = isset($params['H']) ? trim($params['H']) : 'localhost';
$port = isset($params['p']) ? intval($params['p']) : 5984;
$database = isset($params['d']) ? strval($params['d']) : null;

if ('' === $host || $port < 1 || 65535 < $port) {
    fwrite(STDERR, "ERROR: Please specify valid hostname and port (-H <HOSTNAME> and -p <PORT>)." . PHP_EOL);
    exit(1);
}

if (!isset($database) || '' === $database) {
    fwrite(STDERR, "ERROR: Please specify database name (-d <DATABASE>)." . PHP_EOL);
    exit(1);
}

// get all docs IDs
$url = "http://{$host}:{$port}/{$database}/_all_docs";
fwrite(STDERR, "Fetching all documents info from db '{$database}' at {$host}:{$port} ..." . PHP_EOL);
$curl = getCommonCurl($url);
$result = trim(curl_exec($curl));
$statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if (200 == $statusCode) {
    $all_docs = json_decode($result, true);
} else {
    // unknown status
    fwrite(STDERR, "ERROR: Unsupported response when fetching all documents info from db '{$database}' (http status code = {$statusCode}) " . PHP_EOL);
    exit(2);
}

if (!isset($all_docs['rows']) || !count($all_docs['rows']) || !is_array($all_docs['rows'])) {
    fwrite(STDERR, "ERROR: No documents found in db '{$database}'." . PHP_EOL);
    exit(2);
}
// first part of dump
fwrite(STDOUT, '{"new_edits":false,"docs":[' . PHP_EOL);
$first = true;
$count = count($all_docs['rows']);
fwrite(STDERR, "Found {$count} documents..." . PHP_EOL);
foreach ($all_docs['rows'] as $doc) {
    // foreach DOC get all revs
    $url = "http://{$host}:{$port}/{$database}/" . urlencode($doc['id']) . "?revs=true&revs_info=true";
    fwrite(STDERR, "[{$doc['id']}]");
    $curl = getCommonCurl($url);
    $result = curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (200 == $statusCode) {
        $doc_revs = json_decode($result, true);
    } else {
        // unknown status
        fwrite(STDERR, "ERROR: Unsupported response when fetching document [{$doc['id']}] from db '{$database}' (http status code = {$statusCode}) " . PHP_EOL);
        exit(2);
    }
    if (isset($doc_revs['_revs_info']) && count($doc_revs['_revs_info']) > 1) {
        fwrite(STDERR, "" . PHP_EOL);
        // we have more than one revision
        $revs_info = array_reverse($doc_revs['_revs_info']);
        $lastRev = end($revs_info);
        $lastRev = $lastRev['rev'];
        reset($revs_info);
        foreach ($revs_info as $rev) {
            // foreach rev fetch DB/ID?rev=REV&revs=true
            fwrite(STDERR, "[{$doc['id']}] @ {$rev['rev']}");
            if ('available' === $rev['status']) {
                $url = "http://{$host}:{$port}/{$database}/" . urlencode($doc['id']) . "?revs=true&rev=" . urlencode($rev['rev']);
                $curl = getCommonCurl($url);
                $result = curl_exec($curl);
                $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                if (200 == $statusCode) {
                    $full_doc = trim($result);
                } else {
                    // unknown status
                    fwrite(STDERR, "ERROR: Unsupported response when fetching document [{$doc['id']}] revision [{$rev['rev']}] from db '{$database}' (http status code = {$statusCode}) " . PHP_EOL);
                    exit(2);
                }
                fwrite(STDERR, "" . PHP_EOL);
            } elseif ('missing' === $rev['status']) {
                fwrite(STDERR, " = missing" . PHP_EOL);
                continue; // missing docs are not available anyhow
            } elseif ('deleted' === $rev['status']) {
                fwrite(STDERR, " = deleted" . PHP_EOL);
                continue; // we will never get deleted docs as we do not have them in _all_docs list
            } else {
                fwrite(STDERR, " = unsupported revision status" . PHP_EOL);
                continue; // who knows :)
            }
            // add document to dump
            if (!$first) {
                fwrite(STDOUT, ', ' . PHP_EOL . $full_doc);
            } else {
                fwrite(STDOUT, $full_doc);
            }
            $first = false;
        }
    } else {
        // we have only one revision
        unset($doc_revs['_revs_info']);
        $lastRev = $doc_revs['_rev'];
        fwrite(STDERR, "" . PHP_EOL);
        $full_doc = json_encode($doc_revs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($full_doc !== null && $full_doc !== false) {
            if (!$first) {
                fwrite(STDOUT, ', ' . PHP_EOL . $full_doc);
            } else {
                fwrite(STDOUT, $full_doc);
            }
            $first = false;
        }
    }
}
// end of dump
fwrite(STDOUT, PHP_EOL . ']}' . PHP_EOL);
exit(0);

////////////////////////////////////////////////////////////////////////////////

function getCommonCurl($url)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 60);
    curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 curl');
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
    curl_setopt($curl, CURLOPT_BINARYTRANSFER, 1);
    curl_setopt($curl, CURLOPT_URL, $url);
    return $curl;
}

////////////////////////////////////////////////////////////////////////////////

/**
 * Parse incoming parameters like from $_SERVER['argv'] array.
 * @author Anton Bondar <anton@zebooka.com>
 * @param array $params Incoming parameters
 * @param array $reqs Parameters with required value
 * @param array $multiple Parameters that may come multiple times
 * @return array
 */
function parseParameters(array $params, array $reqs = array(), array $multiple = array())
{
    $result = array();
    reset($params);
    while (list(, $p) = each($params)) {
        if ($p[0] == '-' && $p != '-' && $p != '--') {
            $pname = substr($p, 1);
            $value = true;
            if ($pname[0] == '-') {
                // long-opt (--<param>)
                $pname = substr($pname, 1);
                if (strpos($p, '=') !== false) {
                    // value specified inline (--<param>=<value>)
                    list($pname, $value) = explode('=', substr($p, 2), 2);
                }
            }
            $nextparam = current($params);
            if ($value === true && in_array($pname, $reqs)) {
                if ($nextparam !== false) {
                    list(, $value) = each($params);
                } else {
                    $value = false;
                } // required value for option not found
            }
            if (in_array($pname, $multiple) && isset($result[$pname])) {
                if (!is_array($result[$pname])) {
                    $result[$pname] = array($result[$pname]);
                }
                $result[$pname][] = $value;
            } else {
                $result[$pname] = $value;
            }
        } else {
            if ($p == '--') {
                // all next params are not parsed
                while (list(, $p) = each($params)) {
                    $result[] = $p;
                }
            } else {
                // param doesn't belong to any option
                $result[] = $p;
            }
        }
    }
    return $result;
}
