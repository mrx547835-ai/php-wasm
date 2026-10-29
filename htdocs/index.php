<?php

if (!function_exists('curl_init')) {
    function curl_init($url = null) { return ['url' => $url, 'options' => []]; }
    function curl_setopt(&$ch, $opt, $val) { $ch['options'][$opt] = $val; }
    function curl_setopt_array(&$ch, $options) { $ch['options'] = $options; }
    function curl_exec($ch) {
        $url = $ch['options'][CURLOPT_URL] ?? $ch['url'];
        return @file_get_contents($url);
    }
    function curl_close($ch) {}
    function curl_errno($ch) { return 0; }
    function curl_error($ch) { return ''; }
    function curl_getinfo($ch, $opt = 0) { return 200; }
}
/**
 * Universal KaiDownloader - Server-Rendered PHP Edition
 * Fluid Layout supporting 128x160 QQVGA up to widescreen desktops.
 */

// Ensure curl is enabled
if (!extension_loaded('curl')) {
    die("Error: The PHP cURL extension is required. Please enable it in your php.ini.");
}

// -------------------------------------------------------------------------
// CLI AUTO-START
// -------------------------------------------------------------------------
if (php_sapi_name() === 'cli' && !isset($_SERVER['SERVER_SOFTWARE'])) {
    $port = 3000;
    $filename = basename(__FILE__);
    $url = "http://localhost:$port/$filename";
    $platform = PHP_OS_FAMILY;
    $cmd = "";

    if ($platform === 'Darwin') {
        $cmd = "open " . escapeshellarg($url);
    } elseif ($platform === 'Windows') {
        $cmd = "start " . escapeshellarg($url);
    } else {
        $cmd = "xdg-open " . escapeshellarg($url);
    }

    echo "API Server starting at $url\n";
    echo "Opening browser...\n";
    @exec($cmd);

    passthru("php -S localhost:$port " . escapeshellarg(__FILE__));
    exit;
}

// -------------------------------------------------------------------------
// DIRECT NATIVE DOWNLOAD PROXY [1]
// -------------------------------------------------------------------------
if (isset($_GET['download_proxy'])) {
    $streamUrl = $_GET['download_proxy'];
    $title = isset($_GET['title']) ? trim($_GET['title']) : 'video';
    
    // Clean title for safe cross-platform file naming (ASCII fallback)
    $safeTitle = preg_replace('/[^A-Za-z0-9_\-\. ]/', '', $title);
    if (empty($safeTitle)) {
        $safeTitle = 'video';
    }
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Force download headers for mobile and desktop browsers
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeTitle . '.mp4"; filename*=UTF-8\'\'' . rawurlencode($title) . '.mp4');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    $ch = curl_init($streamUrl);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
    
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
        echo $data;
        flush();
        return strlen($data);
    });
    
    curl_exec($ch);
    curl_close($ch);
    exit;
}

// -------------------------------------------------------------------------
// CORE UTILITY FUNCTIONS
// -------------------------------------------------------------------------

function httpRequest($url, $method = 'GET', $headers = [], $postData = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($postData) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
    }

    if (!empty($headers)) {
        $curlHeaders = [];
        foreach ($headers as $key => $val) {
            $curlHeaders[] = "$key: $val";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL Error: $error");
    }
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new Exception("HTTP request failed with status code $httpCode");
    }

    return $response;
}

function unescapeHtml($text) {
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function extractYtInitialData($html) {
    $regex = '/(?:window\["ytInitialData"\]|ytInitialData|var ytInitialData)\s*=\s*({.*?});(?=\s*<\/script>|\s*window\[)/s';
    if (preg_match($regex, $html, $match)) {
        $decoded = json_decode($match[1], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return null;
}

function extractYtPlayerResponse($html) {
    $regex = '/(?:ytInitialPlayerResponse\s*=\s*|ytInitialPlayerResponse\s?=\s?)(.+?)(?:;var|;\(function|\)?;|;\s*if|;\s*ytplayer\.|;\s*<\/script)/s';
    if (preg_match($regex, $html, $match)) {
        $decoded = json_decode($match[1], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return null;
}

function extractVideoId($input) {
    if (!$input) return null;
    if (strpos($input, 'youtube.com') !== false || strpos($input, 'youtu.be') !== false) {
        $urlParts = parse_url($input);
        if (isset($urlParts['host']) && $urlParts['host'] === 'youtu.be') {
            return ltrim($urlParts['path'] ?? '', '/');
        }
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParts);
            if (isset($queryParts['v'])) {
                return $queryParts['v'];
            }
        }
        if (preg_match('/(?:v=|\/embed\/|\/watch\?v=|\/\d+\/|\/vi\/|youtu\.be\/|shorts\/)([^#\&\?]*)/', $input, $match)) {
            return $match[1];
        }
    }
    return $input;
}

function getChannelId($channelUrl) {
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
        'Cookie' => 'CONSENT=PENDING+999; YES+',
        'Accept-Language' => 'en-US,en;q=0.9'
    ];
    $html = httpRequest($channelUrl, 'GET', $headers);
    if (preg_match('/<meta property="og:url" content="https:\/\/www\.youtube\.com\/channel\/(UC[\w-]+)"/', $html, $ogUrlMatch)) {
        return $ogUrlMatch[1];
    }
    if (preg_match('/"browseId":"(UC[\w-]+)"/', $html, $browseIdMatch)) {
        return $browseIdMatch[1];
    }
    throw new Exception("Unable to locate Channel ID.");
}

function fetchVideosFromRss($channelId) {
    $rssUrl = "https://www.youtube.com/feeds/videos.xml?channel_id=$channelId";
    $xml = httpRequest($rssUrl);
    $videos = [];
    if (preg_match_all('/<entry>([\s\S]*?)<\/entry>/', $xml, $matches)) {
        foreach ($matches[1] as $entryContent) {
            preg_match('/<title>([\s\S]*?)<\/title>/', $entryContent, $titleMatch);
            preg_match('/<yt:videoId>([\s\S]*?)<\/yt:videoId>/', $entryContent, $idMatch);
            preg_match('/<published>([\s\S]*?)<\/published>/', $entryContent, $publishedMatch);
            if (!empty($titleMatch) && !empty($idMatch)) {
                $videos[] = [
                    'title' => unescapeHtml(trim($titleMatch[1])),
                    'videoId' => trim($idMatch[1]),
                    'url' => "https://www.youtube.com/watch?v=" . trim($idMatch[1]),
                    'published' => !empty($publishedMatch) ? trim($publishedMatch[1]) : "N/A"
                ];
            }
        }
    }
    return $videos;
}

function searchVideos($query) {
    $searchUrl = "https://www.youtube.com/results?search_query=" . urlencode($query);
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
        'Cookie' => 'CONSENT=PENDING+999; YES+',
        'Accept-Language' => 'en-US,en;q=0.9'
    ];
    $html = httpRequest($searchUrl, 'GET', $headers);
    $initialData = extractYtInitialData($html);
    if (!$initialData) throw new Exception("Could not parse search results.");
    $contents = $initialData['contents']['twoColumnSearchResultsRenderer']['primaryContents']['sectionListRenderer']['contents'] ?? null;
    if (!$contents) throw new Exception("Invalid search payload.");
    $videos = [];
    foreach ($contents as $section) {
        $items = $section['itemSectionRenderer']['contents'] ?? null;
        if ($items && is_array($items)) {
            foreach ($items as $item) {
                $videoRenderer = $item['videoRenderer'] ?? null;
                if ($videoRenderer) {
                    $title = $videoRenderer['title']['runs'][0]['text'] ?? "Untitled";
                    $videoId = $videoRenderer['videoId'] ?? null;
                    $videos[] = [
                        'title' => unescapeHtml($title),
                        'videoId' => $videoId,
                        'url' => "https://www.youtube.com/watch?v=$videoId",
                        'views' => $videoRenderer['viewCountText']['simpleText'] ?? "N/A",
                        'published' => $videoRenderer['publishedTimeText']['simpleText'] ?? "N/A",
                        'thumbnail' => $videoRenderer['thumbnail']['thumbnails'][0]['url'] ?? ""
                    ];
                }
            }
        }
    }
    return $videos;
}

function getBestProgressiveStream($streams) {
    $progressive = array_filter($streams, function($s) {
        return isset($s['mimeType']) && 
               strpos($s['mimeType'], 'codecs="avc1') !== false && 
               strpos($s['mimeType'], 'mp4a') !== false;
    });
    if (count($progressive) > 0) {
        usort($progressive, function($a, $b) {
            return $b['itag'] - $a['itag'];
        });
        return array_values($progressive)[0];
    }
    return !empty($streams) ? $streams[0] : null;
}

function getVideoStreamInfo($videoId, $scriptUrl) {
    $videoUrl = "https://www.youtube.com/watch?v=$videoId";
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
        'Cookie' => 'CONSENT=PENDING+999; YES+',
        'Accept-Language' => 'en-US,en;q=0.9'
    ];
    $html = httpRequest($videoUrl, 'GET', $headers);
    $playerResponse = null;
    preg_match('/"VISITOR_DATA":\s*"([^"]+)"/', $html, $visitorMatch);
    $visitorId = !empty($visitorMatch) ? $visitorMatch[1] : null;

    if ($visitorId) {
        try {
            preg_match('/"innertubeApiKey":\s*"([^"]+)"/i', $html, $apiKeyMatch);
            if (empty($apiKeyMatch)) {
                preg_match('/"apiKey":\s*"([^"]+)"/i', $html, $apiKeyMatch);
            }
            $apiKey = !empty($apiKeyMatch) ? $apiKeyMatch[1] : null;
            $apiUrl = "https://www.youtube.com/youtubei/v1/player";
            if ($apiKey) {
                $apiUrl .= "?key=" . $apiKey;
            }
            $payload = json_encode([
                "videoId" => $videoId,
                "context" => [
                    "client" => [
                        "clientName" => "ANDROID_VR",
                        "clientVersion" => "1.60.19"
                    ]
                ]
            ]);
            $apiHeaders = [
                'Content-Type' => 'application/json',
                'X-Goog-Visitor-Id' => $visitorId,
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36'
            ];
            $apiResponse = httpRequest($apiUrl, 'POST', $apiHeaders, $payload);
            $json = json_decode($apiResponse, true);
            if ($json && isset($json['streamingData'])) {
                $playerResponse = $json;
            }
        } catch (Exception $e) {
            // Fallback inside playerResponse parsing below
        }
    }

    if (!$playerResponse) {
        $playerResponse = extractYtPlayerResponse($html);
    }
    if (!$playerResponse) {
        throw new Exception("Could not extract player response.");
    }
    $streamingData = $playerResponse['streamingData'] ?? null;
    if (!$streamingData) {
        throw new Exception("No streaming data was found.");
    }

    $title = $playerResponse['videoDetails']['title'] ?? "Unknown Title";
    $author = $playerResponse['videoDetails']['author'] ?? "Unknown Channel";
    $duration = $playerResponse['videoDetails']['lengthSeconds'] ?? "N/A";
    $formats = $streamingData['formats'] ?? [];
    $adaptiveFormats = $streamingData['adaptiveFormats'] ?? [];
    $streams = [];
    $allFormats = array_merge($formats, $adaptiveFormats);

    foreach ($allFormats as $format) {
        $streamUrl = $format['url'] ?? null;
        $isCipher = false;
        if (!$streamUrl && (isset($format['signatureCipher']) || isset($format['cipher']))) {
            $isCipher = true;
            $cipher = $format['signatureCipher'] ?? $format['cipher'];
            parse_str($cipher, $params);
            $urlVal = $params['url'] ?? null;
            $sigVal = $params['s'] ?? null;
            $spVal = $params['sp'] ?? 'sig';
            if ($urlVal) {
                $streamUrl = "$urlVal&$spVal=$sigVal";
            }
        }
        if ($streamUrl) {
            $streams[] = [
                'itag' => $format['itag'] ?? null,
                'mimeType' => $format['mimeType'] ?? null,
                'quality' => $format['qualityLabel'] ?? $format['quality'] ?? "N/A",
                'fps' => $format['fps'] ?? "N/A",
                'isCipherProtected' => $isCipher,
                'directUrl' => $streamUrl,
                'proxyUrl' => $scriptUrl . "?proxy_stream=" . urlencode($streamUrl)
            ];
        }
    }
    $bestDownloadStream = getBestProgressiveStream($streams);
    
    // Pattern to construct a reliable medium quality thumbnail url statically
    $thumbnail = "https://i.ytimg.com/vi/{$videoId}/mqdefault.jpg";

    return [
        'title' => $title,
        'author' => $author,
        'durationSeconds' => $duration,
        'thumbnail' => $thumbnail,
        'downloadUrl' => $bestDownloadStream ? $bestDownloadStream['directUrl'] : null,
        'proxyDownloadUrl' => $bestDownloadStream ? $bestDownloadStream['proxyUrl'] : null,
        'quality' => $bestDownloadStream ? $bestDownloadStream['quality'] : null,
        'streams' => $streams
    ];
}

// -------------------------------------------------------------------------
// SERVER ROUTING & VIEW CONTROLLER
// -------------------------------------------------------------------------

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:3000';
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/ytd.php';
$scriptUrl = $protocol . $host . $scriptPath;

$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$videoId = isset($_GET['id']) ? trim($_GET['id']) : '';

if ($page === 'search' && !empty($query)) {
    $extractedId = extractVideoId($query);
    if ($extractedId && strlen($extractedId) === 11 && !strpos($query, ' ') && !strpos($query, '/channel/') && !strpos($query, '/@')) {
        header("Location: ?page=video&id=" . urlencode($extractedId));
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KaiDownloader Server-Driven</title>
  <style>
    /* CSS Optimized for 128x160 QQVGA and fluid scales upwards */
    body {
      margin: 0; padding: 0;
      background-color: #202020;
      color: #FFFFFF;
      font-family: 'Roboto', 'Helvetica Neue', Helvetica, Arial, sans-serif;
      font-size: 9px; /* Very small base for tiny screens */
    }
    
    .app-container {
      width: 100%;
      margin: 0 auto;
      background-color: #202020;
      min-height: 100vh;
      box-sizing: border-box;
    }

    .kai-header {
      background-color: #0093E0;
      color: #FFFFFF;
      text-align: center;
      font-weight: bold;
      padding: 6px 3px;
      font-size: 11px;
    }

    .kai-content {
      padding: 4px;
    }

    .search-input {
      width: 100%;
      background-color: #000000;
      border: 1px solid #555;
      color: #FFFFFF;
      padding: 5px;
      margin-bottom: 4px;
      box-sizing: border-box;
      font-size: 10px;
    }

    .btn {
      background-color: #0093E0;
      color: #FFFFFF;
      border: none;
      padding: 5px 8px;
      font-weight: bold;
      cursor: pointer;
      display: inline-block;
      text-decoration: none;
      text-align: center;
      font-size: 10px;
      box-sizing: border-box;
    }
    
    .btn:focus, .btn:hover {
      background-color: #05AEF2 !important;
      outline: 1px solid #FFF;
    }

    .btn-green {
      background-color: #00A539;
    }
    .btn-green:focus, .btn-green:hover {
      background-color: #00C846 !important;
    }

    .btn-block {
      display: block;
      width: 100%;
    }

    /* Video Row cards with classic floated clear-fix [1] */
    .video-card {
      background-color: #000000;
      border: 1px solid #333;
      margin-bottom: 5px;
      padding: 4px;
      text-decoration: none;
      color: #FFFFFF;
      display: block;
      overflow: hidden; /* Automatic float clearing [1] */
    }

    .video-card:focus, .video-card:hover {
      background-color: #05AEF2;
      color: #FFFFFF;
      outline: 1px solid #FFF;
    }

    /* Miniature dynamic list thumbnails */
    .list-thumb {
      width: 35px;
      height: auto;
      float: left;
      margin-right: 4px;
      border: 1px solid #222;
      background-color: #000;
    }

    .video-details {
      overflow: hidden;
    }

    .video-title {
      font-weight: bold;
      margin: 0 0 2px 0;
      font-size: 10px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .video-meta {
      font-size: 8px;
      color: #aaaaaa;
    }

    /* Dynamic prominent detail thumbnail */
    .detail-thumb {
      width: 100%;
      max-width: 320px;
      height: auto;
      display: block;
      margin: 0 auto 8px auto;
      border: 1px solid #333;
    }

    .back-btn-wrapper {
      margin-bottom: 6px;
    }

    .error-box {
      background-color: #7b0000;
      border: 1px solid #ff3333;
      padding: 6px;
      font-size: 10px;
      margin-bottom: 6px;
    }

    /* Full-screen Overlay scales cleanly for small & large screens */
    .fullscreen-overlay {
      display: none;
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0, 0, 0, 0.95);
      z-index: 10000;
      color: #FFFFFF;
      text-align: center;
      padding-top: 15%;
      box-sizing: border-box;
    }

    .overlay-title {
      font-size: 10px;
      font-weight: bold;
      margin-bottom: 4px;
    }

    .overlay-quality {
      font-size: 9px;
      color: #05AEF2;
      font-weight: bold;
      margin-bottom: 6px;
    }

    .spinner {
      border: 2px solid #333;
      border-top: 2px solid #00A539;
      border-radius: 50%;
      width: 16px;
      height: 16px;
      animation: spin 1s linear infinite;
      margin: 10px auto;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* --------------------------------------------------------- */
    /* RESPONSIVE SCALING UP (QVGA 240px and wider layouts)      */
    /* --------------------------------------------------------- */
    @media (min-width: 240px) {
      body {
        font-size: 12px;
      }
      .kai-header {
        font-size: 14px;
        padding: 10px 5px;
      }
      .kai-content {
        padding: 8px;
      }
      .search-input {
        padding: 8px;
        margin-bottom: 6px;
        font-size: 11px;
      }
      .btn {
        padding: 8px 12px;
        font-size: 11px;
      }
      .video-card {
        padding: 6px;
        margin-bottom: 8px;
      }
      .list-thumb {
        width: 55px;
        margin-right: 8px;
      }
      .video-title {
        font-size: 11px;
        margin-bottom: 3px;
      }
      .video-meta {
        font-size: 9px;
      }
      .back-btn-wrapper {
        margin-bottom: 8px;
      }
      .error-box {
        font-size: 11px;
        margin-bottom: 8px;
      }
      .fullscreen-overlay {
        padding-top: 30%;
      }
      .overlay-title {
        font-size: 13px;
        margin-bottom: 8px;
      }
      .overlay-quality {
        font-size: 11px;
        margin-bottom: 12px;
      }
      .spinner {
        width: 24px;
        height: 24px;
        border-width: 3px;
      }
    }

    /* --------------------------------------------------------- */
    /* DESKTOP / TABLET SCALING (Width limitations)              */
    /* --------------------------------------------------------- */
    @media (min-width: 480px) {
      .app-container {
        max-width: 600px;
        border-left: 1px solid #333;
        border-right: 1px solid #333;
      }
    }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="kai-header">☰ KAIDOWNLOADER</div>

    <div class="kai-content">

      <!-- ========================================== -->
      <!-- VIEW: HOME                                 -->
      <!-- ========================================== -->
      <?php if ($page === 'home'): ?>
        <form method="GET" action="">
          <input type="hidden" name="page" value="search">
          <input type="text" name="q" class="search-input" placeholder="Search query or YouTube URL..." required>
          <input type="submit" value="SEARCH / FIND" class="btn btn-block">
        </form>

        <p style="text-align:center; color:#aaa; font-size:9px; margin-top:20px;">
          Type any keywords, video URLs, or handles above to locate downloads easily.
        </p>

      <!-- ========================================== -->
      <!-- VIEW: SEARCH RESULTS                       -->
      <!-- ========================================== -->
      <?php elseif ($page === 'search'): ?>
        <div class="back-btn-wrapper">
          <a href="?page=home" class="btn btn-block">&lt; BACK TO SEARCH</a>
        </div>

        <?php
        try {
            $videos = searchVideos($query);
            if (empty($videos)) {
                echo "<p style='text-align:center;'>No search results found.</p>";
            } else {
                foreach ($videos as $video) {
                    $detailsUrl = "?page=video&id=" . urlencode($video['videoId']) . "&q=" . urlencode($query);
                    ?>
                    <a href="<?= $detailsUrl ?>" class="video-card">
                      <?php if (!empty($video['thumbnail'])): ?>
                        <img src="<?= htmlspecialchars($video['thumbnail']) ?>" class="list-thumb" alt="thumb">
                      <?php endif; ?>
                      <div class="video-details">
                        <div class="video-title"><?= htmlspecialchars($video['title']) ?></div>
                        <div class="video-meta">Views: <?= htmlspecialchars($video['views']) ?> | <?= htmlspecialchars($video['published']) ?></div>
                      </div>
                    </a>
                    <?php
                }
            }
        } catch (Exception $e) {
            echo "<div class='error-box'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>

      <!-- ========================================== -->
      <!-- VIEW: VIDEO DETAILS / STREAMS DOWNLOAD     -->
      <!-- ========================================== -->
      <?php elseif ($page === 'video'): ?>
        <div class="back-btn-wrapper">
          <a href="?page=search&q=<?= urlencode($query) ?>" class="btn btn-block">&lt; BACK TO LIST</a>
        </div>

        <?php
        try {
            $meta = getVideoStreamInfo($videoId, $scriptUrl);
            ?>
            <div style="text-align: center; margin-bottom: 10px;">
              <div style="font-weight: bold; font-size: 11px; margin-bottom: 6px;">
                <?= htmlspecialchars($meta['title']) ?>
              </div>
              
              <?php if (!empty($meta['thumbnail'])): ?>
                <img src="<?= htmlspecialchars($meta['thumbnail']) ?>" class="detail-thumb" alt="thumbnail">
              <?php endif; ?>

              <div style="font-size: 9px; color: #aaa; margin-top: 4px;">
                Channel: <?= htmlspecialchars($meta['author']) ?>
              </div>
            </div>

            <p style="font-weight: bold; border-bottom: 1px solid #444; margin-bottom: 6px; padding-bottom: 2px;">
              Download Formats:
            </p>

            <?php if (empty($meta['streams'])): ?>
              <p style="text-align:center; color:#ff3333;">No compatible streams resolved.</p>
            <?php else: ?>
              <?php foreach ($meta['streams'] as $stream): 
                $mimeClean = explode(';', $stream['mimeType'])[0];
                $proxyDownloadUrl = "?download_proxy=" . urlencode($stream['directUrl']) . "&title=" . urlencode($meta['title']);
                ?>
                <div style="background-color: #000; border: 1px solid #333; padding: 6px; margin-bottom: 6px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td align="left" style="color: #05AEF2; font-weight: bold; font-size: 10px;">
                        <?= htmlspecialchars($stream['quality']) ?>
                        <div style="font-size: 8px; color:#888; font-weight: normal;"><?= htmlspecialchars($mimeClean) ?></div>
                      </td>
                      <td align="right">
                        <a href="<?= $proxyDownloadUrl ?>" 
                           download="<?= htmlspecialchars($meta['title']) ?>.mp4"
                           class="btn btn-green download-action-btn" 
                           data-quality="<?= htmlspecialchars($stream['quality']) ?>">
                           DOWNLOAD
                        </a>
                      </td>
                    </tr>
                  </table>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php
        } catch (Exception $e) {
            echo "<div class='error-box'>Error resolving streams: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>

      <?php endif; ?>

    </div>
  </div>

  <!-- ========================================== -->
  <!-- FULL SCREEN MODERN BROWSER LOADING OVERLAY -->
  <!-- ========================================== -->
  <div id="loadingOverlay" class="fullscreen-overlay">
    <div class="overlay-title">Processing Download...</div>
    <div class="overlay-quality" id="loadingQuality">Quality: Resolving...</div>
    <div class="spinner"></div>
    <p style="font-size:8px; color:#aaa; margin-top:10px; padding: 0 10px;">Your media request will download directly to your device.</p>
    <button onclick="document.getElementById('loadingOverlay').style.display='none';" 
            style="background:#333; color:#fff; border:1px solid #555; padding:4px 8px; font-size:9px; cursor:pointer; margin-top: 5px;">
      Dismiss Overlay
    </button>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      var downloadBtns = document.querySelectorAll('.download-action-btn');
      for (var i = 0; i < downloadBtns.length; i++) {
        downloadBtns[i].addEventListener('click', function(e) {
          var quality = this.getAttribute('data-quality') || 'N/A';
          var overlay = document.getElementById('loadingOverlay');
          var qualityLabel = document.getElementById('loadingQuality');
          if (overlay && qualityLabel) {
            qualityLabel.innerText = "Quality: " + quality;
            overlay.style.display = 'block';
          }
        });
      }
    });
  </script>

</body>
</html>
