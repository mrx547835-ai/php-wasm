<?php
/**
 * Universal KaiDownloader - Fixed for PHP-WASM & Browser Execution
 */

// -------------------------------------------------------------------------
// 1. FULL CURL POLYFILL & CONSTANTS FOR BROWSER / WASM
// -------------------------------------------------------------------------
if (!defined('CURLOPT_URL')) define('CURLOPT_URL', 10002);
if (!defined('CURLOPT_RETURNTRANSFER')) define('CURLOPT_RETURNTRANSFER', 19913);
if (!defined('CURLOPT_FOLLOWLOCATION')) define('CURLOPT_FOLLOWLOCATION', 52);
if (!defined('CURLOPT_SSL_VERIFYPEER')) define('CURLOPT_SSL_VERIFYPEER', 64);
if (!defined('CURLOPT_TIMEOUT')) define('CURLOPT_TIMEOUT', 13);
if (!defined('CURLOPT_POST')) define('CURLOPT_POST', 47);
if (!defined('CURLOPT_POSTFIELDS')) define('CURLOPT_POSTFIELDS', 10015);
if (!defined('CURLOPT_HTTPHEADER')) define('CURLOPT_HTTPHEADER', 10023);
if (!defined('CURLOPT_USERAGENT')) define('CURLOPT_USERAGENT', 10018);
if (!defined('CURLINFO_HTTP_CODE')) define('CURLINFO_HTTP_CODE', 2097154);

if (!function_exists('curl_init')) {
    function curl_init($url = null) {
        return ['url' => $url, 'options' => [], 'response' => null, 'http_code' => 200];
    }
    function curl_setopt(&$ch, $opt, $val) {
        $ch['options'][$opt] = $val;
    }
    function curl_setopt_array(&$ch, $options) {
        foreach ($options as $k => $v) {
            $ch['options'][$k] = $v;
        }
    }
    function curl_exec(&$ch) {
        $rawUrl = $ch['options'][CURLOPT_URL] ?? $ch['url'];
        if (!$rawUrl) return false;

        // Browser CORS Bypass: ইউটিউবের রিকোয়েস্ট CORS প্রক্সি দিয়ে পাঠানো
        $targetUrl = "https://api.allorigins.win/raw?url=" . urlencode($rawUrl);

        $headers = $ch['options'][CURLOPT_HTTPHEADER] ?? [];
        if (!empty($ch['options'][CURLOPT_USERAGENT])) {
            $headers[] = 'User-Agent: ' . $ch['options'][CURLOPT_USERAGENT];
        }

        $opts = [
            'http' => [
                'method' => (!empty($ch['options'][CURLOPT_POST])) ? 'POST' : 'GET',
                'header' => implode("\r\n", $headers),
                'content' => $ch['options'][CURLOPT_POSTFIELDS] ?? null,
                'timeout' => $ch['options'][CURLOPT_TIMEOUT] ?? 30,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];

        $context = stream_context_create($opts);
        $res = @file_get_contents($targetUrl, false, $context);

        if ($res === false) {
            // ব্যাকআপ হিসেবে সরাসরি চেষ্টা করা
            $res = @file_get_contents($rawUrl, false, $context);
        }

        $ch['response'] = $res;
        $ch['http_code'] = ($res !== false) ? 200 : 500;
        return $res;
    }
    function curl_close(&$ch) {
        $ch = null;
    }
    function curl_errno($ch) {
        return ($ch && $ch['response'] !== false) ? 0 : 1;
    }
    function curl_error($ch) {
        return ($ch && $ch['response'] === false) ? 'Failed to fetch content' : '';
    }
    function curl_getinfo($ch, $opt = 0) {
        if ($opt === CURLINFO_HTTP_CODE) {
            return $ch['http_code'] ?? 200;
        }
        return 200;
    }
}

// -------------------------------------------------------------------------
// 2. DIRECT DOWNLOAD REDIRECT
// -------------------------------------------------------------------------
if (isset($_GET['download_proxy'])) {
    $streamUrl = $_GET['download_proxy'];
    // মেমোরি ক্র্যাশ এড়াতে সরাসরি ভিডিও স্ট্রিমে রিডাইরেক্ট
    header("Location: " . $streamUrl);
    echo "<script>window.location.href = " . json_encode($streamUrl) . ";</script>";
    echo "<div style='text-align:center;padding:20px;font-family:sans-serif;'>";
    echo "<h3>Downloading Video...</h3>";
    echo "<p>If it does not start automatically, <a href='" . htmlspecialchars($streamUrl) . "' target='_blank'>click here to download</a>.</p>";
    echo "</div>";
    exit;
}

// -------------------------------------------------------------------------
// 3. CORE UTILITY FUNCTIONS
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
        throw new Exception("Network Error: $error");
    }
    curl_close($ch);

    if ($httpCode >= 400 && $httpCode !== 200) {
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
            return ($b['itag'] ?? 0) - ($a['itag'] ?? 0);
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
    $playerResponse = extractYtPlayerResponse($html);

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
        if ($streamUrl) {
            $streams[] = [
                'itag' => $format['itag'] ?? null,
                'mimeType' => $format['mimeType'] ?? null,
                'quality' => $format['qualityLabel'] ?? $format['quality'] ?? "N/A",
                'fps' => $format['fps'] ?? "N/A",
                'directUrl' => $streamUrl
            ];
        }
    }
    $bestDownloadStream = getBestProgressiveStream($streams);
    $thumbnail = "https://i.ytimg.com/vi/{$videoId}/mqdefault.jpg";

    return [
        'title' => $title,
        'author' => $author,
        'durationSeconds' => $duration,
        'thumbnail' => $thumbnail,
        'downloadUrl' => $bestDownloadStream ? $bestDownloadStream['directUrl'] : null,
        'streams' => $streams
    ];
}

// -------------------------------------------------------------------------
// 4. ROUTING & CONTROLLER
// -------------------------------------------------------------------------
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
  <title>KaiDownloader WASM</title>
  <style>
    body {
      margin: 0; padding: 0;
      background-color: #202020;
      color: #FFFFFF;
      font-family: system-ui, sans-serif;
      font-size: 13px;
    }
    .app-container {
      width: 100%;
      max-width: 600px;
      margin: 0 auto;
      min-height: 100vh;
      box-sizing: border-box;
      border-left: 1px solid #333;
      border-right: 1px solid #333;
    }
    .kai-header {
      background-color: #0093E0;
      color: #FFFFFF;
      text-align: center;
      font-weight: bold;
      padding: 10px;
      font-size: 15px;
    }
    .kai-content { padding: 12px; }
    .search-input {
      width: 100%;
      background-color: #000;
      border: 1px solid #555;
      color: #FFF;
      padding: 10px;
      margin-bottom: 8px;
      box-sizing: border-box;
      font-size: 13px;
      border-radius: 4px;
    }
    .btn {
      background-color: #0093E0;
      color: #FFFFFF;
      border: none;
      padding: 10px 14px;
      font-weight: bold;
      cursor: pointer;
      display: inline-block;
      text-decoration: none;
      text-align: center;
      font-size: 13px;
      box-sizing: border-box;
      border-radius: 4px;
    }
    .btn-green { background-color: #00A539; }
    .btn-block { display: block; width: 100%; }
    .video-card {
      background-color: #000;
      border: 1px solid #333;
      margin-bottom: 8px;
      padding: 8px;
      text-decoration: none;
      color: #FFF;
      display: flex;
      gap: 10px;
      border-radius: 4px;
    }
    .list-thumb { width: 90px; height: auto; border-radius: 4px; }
    .video-title { font-weight: bold; margin-bottom: 4px; font-size: 13px; }
    .video-meta { font-size: 11px; color: #aaa; }
    .detail-thumb { width: 100%; max-width: 400px; display: block; margin: 0 auto 10px; border-radius: 6px; }
    .error-box { background-color: #7b0000; border: 1px solid #ff3333; padding: 10px; font-size: 12px; margin-bottom: 10px; border-radius: 4px; }
  </style>
</head>
<body>

  <div class="app-container">
    <div class="kai-header">☰ KAIDOWNLOADER (WASM)</div>

    <div class="kai-content">

      <?php if ($page === 'home'): ?>
        <form method="GET" action="">
          <input type="hidden" name="page" value="search">
          <input type="text" name="q" class="search-input" placeholder="Search keywords or paste YouTube URL..." required>
          <input type="submit" value="SEARCH / FIND" class="btn btn-block">
        </form>
        <p style="text-align:center; color:#aaa; font-size:11px; margin-top:20px;">
          Fast In-Browser YouTube Downloader powered by WebAssembly.
        </p>

      <?php elseif ($page === 'search'): ?>
        <div style="margin-bottom: 10px;">
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
                      <div>
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

      <?php elseif ($page === 'video'): ?>
        <div style="margin-bottom: 10px;">
          <a href="?page=search&q=<?= urlencode($query) ?>" class="btn btn-block">&lt; BACK TO LIST</a>
        </div>

        <?php
        try {
            $meta = getVideoStreamInfo($videoId, '');
            ?>
            <div style="text-align: center; margin-bottom: 15px;">
              <div style="font-weight: bold; font-size: 14px; margin-bottom: 8px;">
                <?= htmlspecialchars($meta['title']) ?>
              </div>
              <?php if (!empty($meta['thumbnail'])): ?>
                <img src="<?= htmlspecialchars($meta['thumbnail']) ?>" class="detail-thumb" alt="thumbnail">
              <?php endif; ?>
              <div style="font-size: 11px; color: #aaa;">Channel: <?= htmlspecialchars($meta['author']) ?></div>
            </div>

            <p style="font-weight: bold; border-bottom: 1px solid #444; padding-bottom: 4px;">Available Streams:</p>

            <?php if (empty($meta['streams'])): ?>
              <p style="text-align:center; color:#ff3333;">No compatible direct streams resolved.</p>
            <?php else: ?>
              <?php foreach ($meta['streams'] as $stream): 
                $mimeClean = explode(';', $stream['mimeType'])[0];
                ?>
                <div style="background-color: #000; border: 1px solid #333; padding: 10px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; border-radius: 4px;">
                  <div>
                    <span style="color: #05AEF2; font-weight: bold;"><?= htmlspecialchars($stream['quality']) ?></span>
                    <span style="font-size: 10px; color:#888; margin-left: 6px;"><?= htmlspecialchars($mimeClean) ?></span>
                  </div>
                  <div>
                    <a href="<?= htmlspecialchars($stream['directUrl']) ?>" 
                       target="_blank"
                       download="<?= htmlspecialchars($meta['title']) ?>.mp4"
                       class="btn btn-green">
                       DOWNLOAD
                    </a>
                  </div>
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

</body>
</html>
