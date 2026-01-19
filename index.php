<?php
session_start();
set_time_limit(300);
ini_set('memory_limit', '512M');
$musicDir = $_SESSION['music_dir'] ?? null;

// --- CLASE PARA EXTRACCIÓN DE METADATOS TÉCNICOS ---
class SimpleAudioInfo {
    public static function getInfo($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'flac') return self::getFlacInfo($path);
        if ($ext === 'mp3') return self::getMp3Info($path);
        if ($ext === 'ogg') return ['codec' => 'OGG', 'bits' => '?', 'rate' => '?', 'bitrate' => 'VBR'];
        return ['codec' => strtoupper($ext), 'bits' => '?', 'rate' => '?', 'bitrate' => '?'];
    }

    private static function getFlacInfo($path) {
        $fp = @fopen($path, 'rb');
        if (!$fp) return null;

        $header = fread($fp, 4);
        if (substr($header, 0, 3) === 'ID3') {
            $rest = fread($fp, 6);
            $sizeRaw = substr($rest, 2, 4);
            $i = unpack('N', $sizeRaw)[1];
            $size = ($i & 0x7F) | (($i & 0x7F00) >> 1) | (($i & 0x7F0000) >> 2) | (($i & 0x7F000000) >> 3);
            fseek($fp, $size, SEEK_CUR);
            $header = fread($fp, 4);
        }

        if ($header !== 'fLaC') { fclose($fp); return null; }

        $blockHeader = fread($fp, 4);
        $info = fread($fp, 34);
        fclose($fp);

        if (strlen($info) < 14) return null;
        $bin = sprintf('%08b%08b%08b%08b', ord($info[10]), ord($info[11]), ord($info[12]), ord($info[13]));
        $sampleRate = bindec(substr($bin, 0, 20));
        $bitsPerSample = bindec(substr($bin, 23, 5)) + 1;

        return [
            'codec' => 'FLAC',
            'bits' => $bitsPerSample . '-bit',
            'rate' => ($sampleRate / 1000) . ' kHz',
            'bitrate' => 'Lossless'
        ];
    }

    private static function getMp3Info($path) {
        $fp = @fopen($path, 'rb');
        if (!$fp) return null;
        fseek($fp, 0, SEEK_SET);
        $head = fread($fp, 8192);
        fclose($fp);

        $start = -1;
        $len = strlen($head);
        for ($i = 0; $i < $len - 1; $i++) {
            if (ord($head[$i]) === 0xFF && (ord($head[$i + 1]) & 0xE0) === 0xE0) {
                $start = $i;
                break;
            }
        }
        if ($start === -1) return ['codec' => 'MP3', 'bits' => '16-bit', 'rate' => '?', 'bitrate' => '?'];

        $b2 = ord($head[$start + 1]);
        $b3 = ord($head[$start + 2]);

        $verID = ($b2 & 0x18) >> 3;
        $brIdx = ($b3 & 0xF0) >> 4;
        $srIdx = ($b3 & 0x0C) >> 2;

        $bitrates = [32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
        $rates = [44100, 48000, 32000];

        $br = isset($bitrates[$brIdx - 1]) ? $bitrates[$brIdx - 1] . ' kbps' : 'VBR';
        $sr = isset($rates[$srIdx]) ? ($rates[$srIdx] / 1000) . ' kHz' : '??';

        return [
            'codec' => 'MP3',
            'bits' => '16-bit',
            'rate' => $sr,
            'bitrate' => $br
        ];
    }
}
























class SimpleCoverExtractor {
    public static function getCover($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'mp3') {
            $cover = self::getId3Cover($path);
            if ($cover) return $cover;
        } elseif ($ext === 'flac') {
            $cover = self::getFlacCover($path);
            if ($cover) return $cover;
        }

        // Método de fuerza bruta (busca JPEG/PNG por bloques)
        $cover = self::scanFileForImage($path);
        if ($cover) return $cover;

        // Fallback: buscar archivo en carpeta
        return self::getFolderCover($path);
    }

    // Escanea el archivo entero por bloques buscando cabeceras de imagen (SOI JPEG o PNG)
    private static function scanFileForImage($path) {
        $fp = @fopen($path, 'rb');
        if (!$fp) return null;

        $chunkSize = 1024 * 1024; // 1MB
        $buffer = '';
        $pos = 0;
        $maxScan = 50 * 1024 * 1024; // 50MB límite
        $maxImg = 6 * 1024 * 1024; // 6MB máximo de imagen

        while (!feof($fp) && $pos < $maxScan) {
            $chunk = fread($fp, $chunkSize);
            if ($chunk === false || $chunk === '') break;
            $searchZone = $buffer . $chunk;

            // Buscar SOI JPEG (\xFF\xD8)
            $offset = 0;
            while (($soi = strpos($searchZone, "\xFF\xD8", $offset)) !== false) {
                $absolutePos = ($pos - strlen($buffer)) + $soi;
                $img = self::extractFromPos($fp, $absolutePos, 'image/jpeg', $maxImg);
                if ($img) { fclose($fp); return $img; }
                $offset = $soi + 2;
            }

            // Buscar PNG signature
            $pngRel = strpos($searchZone, "\x89PNG");
            if ($pngRel !== false) {
                $absolutePos = ($pos - strlen($buffer)) + $pngRel;
                $img = self::extractFromPos($fp, $absolutePos, 'image/png', $maxImg);
                if ($img) { fclose($fp); return $img; }
            }

            $buffer = substr($chunk, -64);
            $pos += strlen($chunk);
        }

        fclose($fp);
        return null;
    }

    // Extrae desde la posición encontrada hasta EOI/IEND (o hasta límite) y valida
    private static function extractFromPos($fp, $pos, $mime, $maxBytes) {
        if (!is_resource($fp)) return null;
        // Guardar posición actual para restaurar si es necesario, aunque aquí cerramos al encontrar
        $currentPos = ftell($fp);
        
        fseek($fp, $pos);
        $data = '';
        $read = 0;
        $step = 65536; // 64KB por lectura

        while (!feof($fp) && $read < $maxBytes) {
            $need = min($step, $maxBytes - $read);
            $part = fread($fp, $need);
            if ($part === false || $part === '') break;
            $data .= $part;
            $read += strlen($part);

            if ($mime === 'image/jpeg' && strpos($data, "\xFF\xD9") !== false) break;
            if ($mime === 'image/png' && strpos($data, "IEND") !== false) break;
        }

        // Validación básica de integridad
        if ($mime === 'image/jpeg') {
            if (strpos($data, "\xFF\xD9") === false) return null;
        } elseif ($mime === 'image/png') {
            if (strpos($data, "IEND") === false) return null;
        }

        return ['mime' => $mime, 'data' => $data];
    }

    private static function getFolderCover($path) {
        $dir = dirname($path);
        $candidates = ['folder.jpg', 'cover.jpg', 'front.jpg', 'album.jpg', 'art.jpg', 'folder.png', 'cover.png'];
        foreach ($candidates as $name) {
            $variations = [$name, ucfirst($name), strtoupper($name), strtolower($name)];
            foreach ($variations as $v) {
                $f = $dir . DIRECTORY_SEPARATOR . $v;
                if (file_exists($f)) {
                    $d = @file_get_contents($f);
                    if ($d) return self::detectMime($d);
                }
            }
        }
        return null;
    }

    private static function detectMime($data) {
        if (strlen($data) >= 2 && substr($data, 0, 2) === "\xFF\xD8") return ['mime' => 'image/jpeg', 'data' => $data];
        if (strlen($data) >= 4 && substr($data, 0, 4) === "\x89PNG") return ['mime' => 'image/png', 'data' => $data];
        return null;
    }

    // ID3 cover parser (CORREGIDO: Busca inicio de imagen dentro del frame)
    private static function getId3Cover($path) {
        $fp = @fopen($path, 'rb');
        if (!$fp) return null;
        $h = fread($fp, 10);
        if (strlen($h) < 10 || substr($h, 0, 3) !== 'ID3') { fclose($fp); return null; }

        $ver = ord($h[3]);
        // ID3v2 size es syncsafe integers (7 bits)
        $szRaw = substr($h, 6, 4);
        $sz = (ord($szRaw[0]) & 0x7F) << 21 | (ord($szRaw[1]) & 0x7F) << 14 | (ord($szRaw[2]) & 0x7F) << 7 | (ord($szRaw[3]) & 0x7F);

        if (ord($h[5]) & 0x40) { // Extended header
            $eh = fread($fp, 4);
            if (strlen($eh) === 4) {
                // Extended header size calculo simplificado
                $ehSz = (ord($eh[0]) & 0x7F) << 21 | (ord($eh[1]) & 0x7F) << 14 | (ord($eh[2]) & 0x7F) << 7 | (ord($eh[3]) & 0x7F);
                fseek($fp, $ehSz, SEEK_CUR);
            }
        }

        $end = ftell($fp) + $sz;
        while (ftell($fp) < $end) {
            if ($ver === 2) {
                $fh = fread($fp, 6);
                if (strlen($fh) < 6) break;
                $id = substr($fh, 0, 3);
                // v2.2 usa 3 bytes para size
                $fs = unpack('N', "\0" . substr($fh, 3, 3))[1];
            } else {
                $fh = fread($fp, 10);
                if (strlen($fh) < 10) break;
                $id = substr($fh, 0, 4);
                $tmp = substr($fh, 4, 4);
                
                // v2.4 size es syncsafe, v2.3 usualmente integer plano
                if ($ver === 4) {
                    $fs = (ord($tmp[0]) & 0x7F) << 21 | (ord($tmp[1]) & 0x7F) << 14 | (ord($tmp[2]) & 0x7F) << 7 | (ord($tmp[3]) & 0x7F);
                } else {
                    $fs = unpack('N', $tmp)[1];
                }
            }

            if ($fs == 0) break; // Padding alcanzado

            // Frame APIC (v2.3/4) o PIC (v2.2)
            if (strpos($id, 'APIC') !== false || strpos($id, 'PIC') !== false) {
                $d = fread($fp, $fs);
                fclose($fp);
                
                // FIX: No asumir que la imagen empieza en el byte 0.
                // APIC structure: Encoding(1) | MIME(str) | Type(1) | Desc(str) | DATA
                // Buscamos la firma de la imagen dentro del payload del frame.
                
                // Buscar JPEG
                $jpgPos = strpos($d, "\xFF\xD8");
                if ($jpgPos !== false) {
                    return ['mime' => 'image/jpeg', 'data' => substr($d, $jpgPos)];
                }
                // Buscar PNG
                $pngPos = strpos($d, "\x89PNG");
                if ($pngPos !== false) {
                    return ['mime' => 'image/png', 'data' => substr($d, $pngPos)];
                }
                return null;
            }
            
            fseek($fp, $fs, SEEK_CUR);
        }
        fclose($fp);
        return null;
    }

    // FLAC COVER parser (CORREGIDO: Offsets del bloque PICTURE)
    private static function getFlacCover($path) {
        $fp = @fopen($path, 'rb');
        if (!$fp) return null;
        
        // Verificar firma FLAC
        $h = fread($fp, 4);
        if (substr($h, 0, 3) === 'ID3') {
            // Saltar ID3 prepended si existe
            $rest = fread($fp, 6);
            $sz = unpack('N', substr($rest, 2, 4))[1];
            $sz = ($sz & 0x7F) | (($sz & 0x7F00) >> 1) | (($sz & 0x7F0000) >> 2) | (($sz & 0x7F000000) >> 3);
            fseek($fp, $sz, SEEK_CUR);
            $h = fread($fp, 4);
        }
        if ($h !== 'fLaC') { fclose($fp); return null; }

        while (!feof($fp)) {
            $bh = fread($fp, 4);
            if (strlen($bh) < 4) break;
            
            $isLast = ord($bh[0]) & 0x80;
            $type = ord($bh[0]) & 0x7F;
            $len = unpack('N', "\0" . substr($bh, 1, 3))[1];
            
            if ($type === 6) { // PICTURE BLOCK
                $data = fread($fp, $len);
                fclose($fp);
                
                if (strlen($data) >= $len) {
                    $pos = 0;
                    
                    // 1. Picture Type (4 bytes) - saltar
                    $pos += 4; 
                    
                    // 2. MIME type length (4 bytes)
                    $mL = unpack('N', substr($data, $pos, 4))[1]; $pos += 4;
                    
                    // 3. MIME type string
                    $mime = substr($data, $pos, $mL); $pos += $mL;
                    
                    // 4. Description length (4 bytes)
                    $dL = unpack('N', substr($data, $pos, 4))[1]; $pos += 4;
                    
                    // 5. Description string - saltar
                    $pos += $dL;
                    
                    // 6. Picture attributes (Width, Height, Depth, Colors) - 4x4 = 16 bytes - saltar
                    $pos += 16;
                    
                    // 7. Picture data length (4 bytes)
                    $imgLen = unpack('N', substr($data, $pos, 4))[1]; $pos += 4;
                    
                    // 8. Picture data
                    if ($pos + $imgLen <= strlen($data)) {
                        return ['mime' => $mime, 'data' => substr($data, $pos, $imgLen)];
                    }
                }
                return null;
            }
            
            fseek($fp, $len, SEEK_CUR);
            if ($isLast) break;
        }
        fclose($fp);
        return null;
    }
}













// Helpers
function json_resp($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function get_mime_type($file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimes = [
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
        'flac' => 'audio/flac', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'opus' => 'audio/opus'
    ];
    return $mimes[$ext] ?? 'application/octet-stream';
}

// --- ROUTER PRINCIPAL ---
if (isset($_GET['action']) || isset($_GET['stream_id'])) {
    $action = $_GET['action'] ?? '';

    // Configuración inicial
    if ($action === 'config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $pathRaw = rtrim($input['path'] ?? '', '/');
        $path = realpath($pathRaw);
        if ($path && is_dir($path) && is_readable($path)) {
            $_SESSION['music_dir'] = $path;
            $cacheFile = sys_get_temp_dir() . '/debianaudio_tracks_cache.json';
            if (file_exists($cacheFile)) @unlink($cacheFile);
            json_resp(['status' => 'ok']);
        } else {
            json_resp(['status' => 'error', 'message' => 'Ruta inválida'], 400);
        }
    }

    // Escaneo de archivos
    if ($action === 'scan') {
        if (!$musicDir || !is_dir($musicDir)) json_resp(['status' => 'error', 'message' => 'No config'], 400);

        $cacheFile = sys_get_temp_dir() . '/debianaudio_tracks_cache.json';
        $cacheTTL = 300;

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTTL)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (isset($cached['data'])) json_resp(['status' => 'ok', 'data' => $cached['data']]);
        }

        $tracks = [];
        $exts = ['mp3','flac','wav','ogg','m4a','aac','opus'];
        $start = microtime(true);

        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($musicDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iter as $f) {
                if (microtime(true) - $start > 25) throw new Exception('Timeout scanning');
                if ($f->isFile() && in_array(strtolower($f->getExtension()), $exts)) {
                    $abs = $f->getPathname();
                    $rel = ltrim(str_replace($musicDir, '', $abs), '/\\');
                    $id = rtrim(strtr(base64_encode($rel), '+/', '-_'), '=');

                    $tracks[] = [
                        'id'   => $id,
                        'name' => $f->getFilename(),
                        'size' => round($f->getSize() / 1048576, 2)
                    ];
                }
            }

            usort($tracks, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
            @file_put_contents($cacheFile, json_encode(['data' => $tracks], JSON_UNESCAPED_UNICODE));
            json_resp(['status' => 'ok', 'data' => $tracks]);
        } catch (Exception $e) {
            json_resp(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // Info Técnica
    if ($action === 'info' && isset($_GET['id'])) {
        $id = $_GET['id'];
        $pad = strlen($id) % 4;
        if ($pad) $id .= str_repeat('=', 4 - $pad);
        $rel = base64_decode(strtr($id, '-_', '+/'));

        if ($rel === false) { http_response_code(403); exit; }

        $file = realpath($musicDir . DIRECTORY_SEPARATOR . $rel);
        $base = realpath($musicDir);

        if ($file && $base && strpos($file, $base) === 0 && file_exists($file)) {
            $info = SimpleAudioInfo::getInfo($file);

            $sz = filesize($file);
            $units = ['B','KB','MB','GB'];
            $factor = floor((strlen($sz) - 1) / 3);
            $info['size_fmt'] = sprintf("%.2f %s", $sz / pow(1024, $factor), $units[$factor]);

            json_resp(['status' => 'ok', 'data' => $info]);
        }
        json_resp(['status' => 'error'], 404);
    }

    // --- CARÁTULA (OGG + FLAC + MP3 nuclear) ---
    if ($action === 'cover' && isset($_GET['id'])) {
        ini_set('display_errors', 0);
        error_reporting(0);
        while (ob_get_level()) ob_end_clean();

        $id = $_GET['id'];
        $pad = strlen($id) % 4;
        if ($pad) $id .= str_repeat('=', 4 - $pad);
        $rel = base64_decode(strtr($id, '-_', '+/'));

        if ($rel === false) { http_response_code(403); exit; }

        $file = realpath($musicDir . DIRECTORY_SEPARATOR . $rel);
        $base = realpath($musicDir);

        if ($file && $base && strpos($file, $base) === 0 && file_exists($file)) {
            $img = SimpleCoverExtractor::getCover($file);

            if ($img && !empty($img['data'])) {
                while (ob_get_level()) ob_end_clean();

                header('Content-Type: ' . $img['mime']);
                header('Content-Length: ' . strlen($img['data']));
                header('Cache-Control: public, max-age=3600');
                header('Content-Transfer-Encoding: binary');
                header('Pragma: public');
                echo $img['data'];
                exit;
            }
        }

        while (ob_get_level()) ob_end_clean();
        http_response_code(404);
        exit;
    }

    // Streaming
    if (isset($_GET['stream_id'])) {
        $id = $_GET['stream_id'];
        $pad = strlen($id) % 4;
        if ($pad) $id .= str_repeat('=', 4 - $pad);
        $rel = base64_decode(strtr($id, '-_', '+/'));

        if ($rel === false) { http_response_code(403); exit; }

        $file = realpath($musicDir . DIRECTORY_SEPARATOR . $rel);
        $base = realpath($musicDir);

        if ($file && $base && strpos($file, $base) === 0 && file_exists($file)) {
            $size = filesize($file);
            $mime = get_mime_type($file);
            $fnEsc = rawurlencode(basename($file));

            header("Content-Type: $mime");
            header('Accept-Ranges: bytes');
            header("Content-Disposition: inline; filename*=UTF-8''{$fnEsc}");

            $begin = 0; $end = $size - 1;

            if (isset($_SERVER['HTTP_RANGE'])) {
                if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
                    $begin = intval($m[1]);
                    if ($m[2] !== '') $end = intval($m[2]);
                    if ($begin >= $size || $end >= $size || $begin > $end) {
                        http_response_code(416); header("Content-Range: bytes */$size"); exit;
                    }
                    http_response_code(206);
                    header("Content-Range: bytes $begin-$end/$size");
                    header("Content-Length: " . ($end - $begin + 1));
                }
            } else {
                header("Content-Length: $size");
            }

            $fp = fopen($file, 'rb');
            fseek($fp, $begin);
            while (!feof($fp) && ftell($fp) <= $end) {
                echo fread($fp, min(8192, $end - ftell($fp) + 1));
                flush();
            }
            fclose($fp);
            exit;
        }
        http_response_code(404); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#161b22">
<title>Debian Audio</title>
<link rel="stylesheet" href="css.css?v=<?= time() ?>">
</head>
<body>
<div class="load" id="loader">CARGANDO...</div>

<div class="modal" id="cfg-modal">
    <div class="box">
        <div class="box-header">
            <h3>Directorio</h3>
            <button class="btn" onclick="document.getElementById('cfg-modal').style.display='none'">
                <svg class="ico" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
        </div>
        <input id="m-path" class="m-in" placeholder="/ruta/musica" value="<?= htmlspecialchars($musicDir ?? '') ?>">
        <button onclick="savePath()" class="m-btn">GUARDAR</button>
    </div>
</div>

<div class="menu" id="pl-menu">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <h3>Playlists</h3>
        <button class="btn" onclick="togglePlaylist()">
            <svg class="ico" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
    </div>
    <button class="m-btn" style="margin-bottom:15px" onclick="newPl()">+ Nueva Playlist</button>
    <div id="pl-list"></div>
    <h4 style="margin:25px 0 15px 0;color:var(--a);border-top:1px solid var(--b);padding-top:15px">Favoritos</h4>
    <div id="fav-list"></div>
</div>

<div class="top">
    <div class="brand">Debian<span>Audio</span></div>
    <input id="search" class="search" placeholder="Buscar..." onkeyup="debouncedFilter()">
    <button class="btn" onclick="init()" title="Recargar">
        <svg class="ico" viewBox="0 0 24 24"><path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
    </button>
    <button class="btn" onclick="document.getElementById('cfg-modal').style.display='grid'" title="Configuración">
        <svg class="ico" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.58 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
    </button>
</div>

<div class="app-layout">
    
    <aside class="side-player">
        
        <div class="cover-container" onclick="flipCover()">
            <div class="cover-card" id="c-card">
                <div class="face front">
                    <div id="cover-fallback" class="cover-fallback">
                        <svg class="ico-xl" viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-4z"/></svg>
                    </div>
                    <div id="cover-art" class="cover-img"></div>
                    <div id="vis">
                        <canvas id="cvs"></canvas>
                    </div>
                </div>
                <div class="face back">
                    <div class="meta-row">
                        <small>CODEC</small>
                        <b id="m-codec">--</b>
                    </div>
                    <div class="meta-row">
                        <small>BITRATE</small>
                        <b id="m-bitrate">--</b>
                    </div>
                    <div class="meta-row">
                        <small>MUESTREO</small>
                        <b id="m-rate">--</b>
                    </div>
                    <div class="meta-row">
                        <small>BITS</small>
                        <b id="m-bits">--</b>
                    </div>
                    <div class="meta-row" style="border:none">
                        <small>PESO</small>
                        <b id="m-size">--</b>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-large">
            <div class="name" id="t-name">Select Track</div>
            <div class="meta" id="t-meta">--</div>
        </div>

        <div class="prog-box">
            <span class="time" id="cur">0:00</span>
            <input type="range" id="seek" min="0" max="1000" value="0">
            <span class="time" id="dur">0:00</span>
        </div>

        <div class="ctl-row">
            <button class="p-btn" id="btn-shuf" onclick="toggleShuffle()" title="Aleatorio">
                <svg class="ico" viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
            </button>
            <button class="p-btn" onclick="play(-1)">
                <svg class="ico" viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button class="p-btn big-play" id="play" onclick="toggle()">
                <svg class="ico-lg" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button class="p-btn" onclick="play(1)">
                <svg class="ico" viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
            </button>
            <button class="p-btn" id="btn-loop" onclick="toggleLoop()" title="Bucle">
                <svg class="ico" viewBox="0 0 24 24"><path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v6z"/></svg>
            </button>
        </div>

        <div class="vol-row">
            <button class="p-btn sm" id="fav-btn" onclick="fav()" title="Favorito">
                <svg class="ico" viewBox="0 0 24 24"><path d="M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z"/></svg>
            </button>
            <button class="p-btn sm" id="btn-eq" onclick="toggleEq()" title="Ecualizador">
                <svg class="ico" viewBox="0 0 24 24"><path d="M3 17v2h6v-2H3zM3 5v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm14 4v-2H11v2h10zm-6-4h2V7h4V5h-4V3h-2v6z"/></svg>
            </button>
            <button class="p-btn sm" id="btn-pl" onclick="togglePlaylist()" title="Playlists">
                <svg class="ico" viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
            </button>
        </div>
        <div class="vol-slider-box">
            <svg class="ico-sm" style="color:var(--t-dim)" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
            <input type="range" min="0" max="1" step="0.05" value="1" oninput="aud.volume=this.value">
        </div>

        <div class="eq-panel" id="eq-p">
            <div class="eq-head">
                <span>EQ</span>
                <button class="btn sm" onclick="toggleEq()">
                    <svg class="ico" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                </button>
            </div>
            <div class="bands" id="eq-bands"></div>
        </div>

    </aside>

    <main class="main-list">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Track</th>
                    <th>Size</th>
                </tr>
            </thead>
            <tbody id="list"></tbody>
        </table>
    </main>

</div>

<script src="js.js?v=<?= time() ?>"></script>
</body>
</html>
