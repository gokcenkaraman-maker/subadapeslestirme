<?php
/*
  Şubadap oyunları - günlük sıralama sunucusu (tek dosya, PHP 7.4+)

  KURULUM
  1) Bu dosyayı index.html ile AYNI klasöre yükleyin. Başka bir şey gerekmez.
  2) "skor-veri" klasörü ilk skor geldiğinde kendiliğinden oluşur (klasöre yazma izni olmalı).
  3) Oyun başka bir adreste duruyorsa index.html içindeki LEADERBOARD_URL satırına bu dosyanın tam adresini yazın.

  - Gün, Türkiye saatine göre gece 00:00'da değişir. Her gün yeni, boş bir sıralama başlar.
  - Her oyun + seviye için ayrı sıralama tutulur. Aynı takma ad aynı gün içinde en yüksek puanıyla yer alır.
  - Eski günlerin kayıtları KEEP_DAYS gün sonra silinir.
*/

$ADMIN_KEY = '';      // Doluysa uygunsuz bir kaydı silebilirsiniz:  skor.php?sil=1&board=find-9&name=TAKMAAD&key=BURAYA_YAZDIĞINIZ_ŞİFRE
$MAX_SCORE = 7000;    // bundan yüksek puan kabul edilmez (hileye karşı üst sınır)
$KEEP_DAYS = 3;
// Uygunsuz takma adlar için BAŞLANGIÇ listesi (kelime olarak eşleşir). Gerekirse genişletin.
$BLOCKED = ['amk', 'aq', 'sik', 'sikik', 'siktir', 'orospu', 'pic', 'piç', 'yarrak', 'got', 'göt', 'ibne', 'gavat'];

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { exit; }

date_default_timezone_set('Europe/Istanbul');
$day = date('Y-m-d');

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function lower($s) { return mb_strtolower($s, 'UTF-8'); }
function clean_name($n) {
    $n = preg_replace('/[^\p{L}\p{N} _\-]/u', '', (string)$n);
    $n = preg_replace('/\s+/u', ' ', $n);
    return trim(mb_substr($n, 0, 12, 'UTF-8'));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) { $in = []; }

$board = (string)($in['board'] ?? $_GET['board'] ?? '');
if (!preg_match('/^(find|memory2?)-\d{1,2}$/', $board)) { fail(400, 'board'); }
$name = clean_name($in['name'] ?? $_GET['name'] ?? '');

$dir = __DIR__ . '/skor-veri';
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true)) { fail(500, 'klasor'); }
    @file_put_contents($dir . '/index.html', '');
}
$file = "$dir/$day-$board.json";

// Yönetici: kayıt silme
if (isset($_GET['sil'])) {
    if ($ADMIN_KEY === '' || !hash_equals($ADMIN_KEY, (string)($_GET['key'] ?? ''))) { fail(403, 'yetki'); }
    $data = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    unset($data[lower($name)]);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

if ($method === 'POST') {
    $score = (int)($in['score'] ?? 0);
    if ($name === '') { fail(400, 'name'); }
    if ($score < 1 || $score > $MAX_SCORE) { fail(400, 'score'); }
    foreach (preg_split('/[^\p{L}\p{N}]+/u', lower($name), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (in_array($w, $BLOCKED, true)) { fail(422, 'name'); }
    }
    // eski günlerin dosyalarını temizle
    $limit = date('Y-m-d', strtotime("-$KEEP_DAYS days"));
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})-/', basename($f), $m) && $m[1] < $limit) { @unlink($f); }
    }
    $fp = fopen($file, 'c+');
    if (!$fp) { fail(500, 'yazma'); }
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true);
    if (!is_array($data)) { $data = []; }
    $key = lower($name);
    if (!isset($data[$key]) || $score > $data[$key]['score']) {
        $data[$key] = ['name' => $name, 'score' => $score];
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
} else {
    $data = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}

$list = array_values($data);
usort($list, function ($a, $b) {
    return ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']);
});
$you = null;
if ($name !== '') {
    foreach ($list as $i => $e) {
        if (lower($e['name']) === lower($name)) { $you = ['rank' => $i + 1, 'score' => $e['score'], 'name' => $e['name']]; break; }
    }
}
echo json_encode(['day' => $day, 'total' => count($list), 'entries' => array_slice($list, 0, 10), 'you' => $you], JSON_UNESCAPED_UNICODE);
