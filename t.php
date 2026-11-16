<?php
// Seguimiento de lectura de propuestas. Recibe eventos de rastreo.js y avisa por correo.
// Datos y configuración fuera del sitio público: ~/propuestas-tracking/ (config.php, visitas.sqlite).
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
$dir = rtrim($home, '/') . '/propuestas-tracking';
if (!is_file("$dir/config.php")) { http_response_code(204); exit; }
$cfg = require "$dir/config.php";

$raw = file_get_contents('php://input', false, null, 0, 4096) ?: '';
$d = json_decode($raw, true);
if (!is_array($d)) { http_response_code(400); exit; }
$tipo = $d['tipo'] ?? '';
if (!in_array($tipo, ['inicio', 'fin'], true)) { http_response_code(400); exit; }
$id = fn($x) => preg_replace('/[^a-z0-9-]/i', '', substr((string)$x, 0, 24));
$num = fn($x, $max) => max(0, min($max, (int)$x));
$v = $id($d['v'] ?? ''); $s = $id($d['s'] ?? ''); $cod = strtolower($id($d['r'] ?? ''));
if ($v === '' || $s === '') { http_response_code(400); exit; }
$n = $num($d['n'] ?? 0, 50); $max = $num($d['max'] ?? 0, 50); $vistos = $num($d['vistos'] ?? 0, 50);
$audios = $num($d['audios'] ?? 0, 500); $seg = $num($d['seg'] ?? 0, 86400);
$ref = substr(preg_replace('/[\x00-\x1f]/', '', (string)($d['ref'] ?? '')), 0, 200);

$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
if ($ua === '' || preg_match('/bot|crawl|spider|preview|headless|lighthouse|facebookexternalhit|slurp|python|curl|wget|playwright|puppeteer/i', $ua)) { http_response_code(204); exit; }

$host = strtolower(preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? ''));
$propuesta = $cfg['propuestas'][$host] ?? $host;
$quien = $cod !== '' ? ($cfg['codigos'][$cod] ?? "código «$cod»") : 'sin código (enlace sin marcar)';
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = trim(explode(',', $ip)[0]);
$ipHash = substr(hash('sha256', ($cfg['sal'] ?? '') . $ip), 0, 16);
$ahora = gmdate('Y-m-d\TH:i:s\Z');

$db = new PDO("sqlite:$dir/visitas.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE IF NOT EXISTS sesiones (s TEXT PRIMARY KEY, v TEXT, host TEXT, codigo TEXT, inicio TEXT, ultimo TEXT,
  n INT, max INT, vistos INT, audios INT, seg INT, ua TEXT, ip_hash TEXT, pais TEXT, ciudad TEXT, red TEXT, ref TEXT,
  aviso_abrio INT DEFAULT 0, aviso_reviso INT DEFAULT 0)");

$fila = $db->prepare('SELECT * FROM sesiones WHERE s = ?'); $fila->execute([$s]); $fila = $fila->fetch(PDO::FETCH_ASSOC);

function geo(string $ip, array $cfg): array {
  if (empty($cfg['geo']) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return ['', '', ''];
  $ctx = stream_context_create(['http' => ['timeout' => 2]]);
  $j = @file_get_contents("http://ip-api.com/json/$ip?fields=status,country,city,org,isp", false, $ctx);
  $g = $j ? json_decode($j, true) : null;
  if (!is_array($g) || ($g['status'] ?? '') !== 'success') return ['', '', ''];
  return [(string)($g['country'] ?? ''), (string)($g['city'] ?? ''), (string)(($g['org'] ?? '') ?: ($g['isp'] ?? ''))];
}
function dispositivo(string $ua): string {
  $so = preg_match('/iPhone|iPad/i', $ua) ? 'iPhone/iPad' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'Mac' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'otro'))));
  $nav = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'otro')));
  return (preg_match('/Mobile|Android|iPhone/i', $ua) ? 'móvil' : 'computadora') . ", $so, $nav";
}
function hora(string $iso): string {
  $t = new DateTime($iso); $t->setTimezone(new DateTimeZone('America/Lima'));
  return $t->format('d/m/Y H:i') . ' (Lima)';
}
function correo(array $cfg, string $asunto, string $cuerpo): void {
  $de = $cfg['de'] ?? 'propuestas@innodite.com';
  $h = "From: Propuestas Innodite <$de>\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
  @mail($cfg['para'], '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $h, "-f$de");
}

if (!$fila) {
  [$pais, $ciudad, $red] = geo($ip, $cfg);
  $db->prepare('INSERT INTO sesiones (s,v,host,codigo,inicio,ultimo,n,max,vistos,audios,seg,ua,ip_hash,pais,ciudad,red,ref) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
     ->execute([$s, $v, $host, $cod, $ahora, $ahora, $n, $max, $vistos, $audios, $seg, $ua, $ipHash, $pais, $ciudad, $red, $ref]);
  $fila = ['s' => $s, 'v' => $v, 'inicio' => $ahora, 'pais' => $pais, 'ciudad' => $ciudad, 'red' => $red, 'aviso_abrio' => 0, 'aviso_reviso' => 0];
} else {
  $db->prepare('UPDATE sesiones SET ultimo=?, max=MAX(max,?), vistos=MAX(vistos,?), audios=MAX(audios,?), seg=MAX(seg,?) WHERE s=?')
     ->execute([$ahora, $max, $vistos, $audios, $seg, $s]);
}
$lugar = trim(implode(', ', array_filter([$fila['ciudad'] ?? '', $fila['pais'] ?? ''])));
$lugar = ($lugar ?: 'ubicación desconocida') . (!empty($fila['red']) ? " · red: {$fila['red']}" : '');

// Aviso 1: alguien abrió la propuesta (uno por visitante y propuesta cada 30 min).
if (!(int)$fila['aviso_abrio']) {
  $q = $db->prepare("SELECT COUNT(*) FROM sesiones WHERE v=? AND host=? AND aviso_abrio=1 AND inicio > ?");
  $q->execute([$v, $host, gmdate('Y-m-d\TH:i:s\Z', time() - 1800)]);
  $antes = $db->prepare('SELECT COUNT(*) FROM sesiones WHERE v=? AND host=? AND s<>?'); $antes->execute([$v, $host, $s]);
  $visita = (int)$antes->fetchColumn() + 1;
  if ((int)$q->fetchColumn() === 0) {
    $db->prepare('UPDATE sesiones SET aviso_abrio=1 WHERE s=?')->execute([$s]);
    correo($cfg, "👀 Abrieron tu propuesta: $propuesta",
      "Alguien abrió tu propuesta de $propuesta.\n\n" .
      "Quién: $quien\nCuándo: " . hora($ahora) . "\nDesde: $lugar\nDispositivo: " . dispositivo($ua) . "\n" .
      ($visita > 1 ? "Visita número $visita de este mismo navegador.\n" : "Primera visita de este navegador.\n") .
      ($ref ? "Llegó desde: $ref\n" : '') .
      "\nSi la revisa con calma (45 s o más, o llega al último paso) te mando otro correo.\nPágina: https://$host\n");
  }
}
// Aviso 2: la revisó (45 s activos o llegó al último paso), uno por sesión.
if ($tipo === 'fin' && !(int)$fila['aviso_reviso'] && ($seg >= 45 || ($n > 0 && $max >= $n))) {
  $db->prepare('UPDATE sesiones SET aviso_reviso=1 WHERE s=?')->execute([$s]);
  $min = intdiv($seg, 60) . ' min ' . ($seg % 60) . ' s';
  correo($cfg, "📖 Revisaron tu propuesta: $propuesta ($max de $n pasos)",
    "Revisaron tu propuesta de $propuesta.\n\nQuién: $quien\nDesde: $lugar\n" .
    "Llegó hasta el paso $max de $n (vio $vistos pasos distintos).\nTiempo con la página a la vista: $min.\n" .
    "Audios reproducidos: $audios.\nAbierta: " . hora($fila['inicio']) . "\nPágina: https://$host\n");
}
http_response_code(204);
