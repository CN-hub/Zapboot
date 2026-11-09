<?php
/* =====================================================================
   ZapBot - servidor (PHP + MySQL do XAMPP).
   Coloque este arquivo na MESMA pasta do index.html (htdocs/zapbot/).
   - Todo acesso exige login (sessão no servidor).
   - O administrador (Dev) vê tudo; cada cliente só recebe e grava o PRÓPRIO ambiente
     e nunca recebe as senhas de ninguém.
   - Senha do administrador: fica no banco (criptografada). Padrão: Dev / 1234@
     (troque em Configurações > Senha do administrador).
   Se o MySQL tiver senha no root, altere $DB_PASS abaixo.
   ===================================================================== */
$DB_HOST = '127.0.0.1';
$DB_NAME = 'zapbot';
$DB_USER = 'root';
$DB_PASS = '';               // XAMPP padrão: sem senha
$MAX_BACKUPS = 100;          // quantos backups do histórico manter
$INTERVALO_BACKUP = 30 * 60; // segundos entre backups automáticos
$SENHA_PADRAO = '1234@';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('America/Fortaleza');

class ErroApi extends Exception {
    public $extra;
    function __construct($m, $code = 400, $extra = []) { parent::__construct($m, $code); $this->extra = $extra; }
}
function resp($d, $code = 200) { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

/* ---------- proteção básica contra uso por outros sites ---------- */
if (($_SERVER['HTTP_X_ZB'] ?? '') !== '1') resp(['ok' => false, 'erro' => 'Acesso negado.'], 403);

ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '28800');
session_name('zbsess');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    resp(['ok' => false, 'erro' => 'Não foi possível conectar ao banco "zapbot". Confira se o MySQL está ligado no XAMPP e se o arquivo zapbot_banco.sql foi executado.'], 500);
}

/* tabelas de segurança (criadas sozinhas, caso o SQL antigo tenha sido usado) */
$pdo->exec("CREATE TABLE IF NOT EXISTS zb_admin (id TINYINT UNSIGNED NOT NULL, usuario VARCHAR(40) NOT NULL, senha_hash VARCHAR(255) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS zb_tentativas (id INT UNSIGNED NOT NULL AUTO_INCREMENT, tipo VARCHAR(20) NOT NULL, chave VARCHAR(64) NOT NULL, quando DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_tent (tipo, chave, quando)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!$pdo->query("SELECT 1 FROM zb_admin WHERE id = 1")->fetch()) {
    $pdo->prepare("INSERT IGNORE INTO zb_admin (id, usuario, senha_hash) VALUES (1, 'dev', ?)")->execute([password_hash($SENHA_PADRAO, PASSWORD_DEFAULT)]);
}

/* ---------- utilidades ---------- */
function dt($ms) { return ($ms && is_numeric($ms) && $ms > 0) ? date('Y-m-d H:i:s', (int)floor($ms / 1000)) : null; }
function dd($s) { return (is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) ? $s : null; }
function s($v, $n = 0) { $v = is_scalar($v) ? (string)$v : ''; return $n ? mb_substr($v, 0, $n) : $v; }
function agora() { return (int)round(microtime(true) * 1000); }
function novoId() { return substr(bin2hex(random_bytes(5)), 0, 7); }
function enc($d) { return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR); }
function ip() { return substr($_SERVER['REMOTE_ADDR'] ?? '0', 0, 64); }

function planosPadrao() {
    return [['id' => 'teste', 'nome' => 'Teste', 'preco' => 0, 'limEmp' => 1], ['id' => 'basico', 'nome' => 'Básico', 'preco' => 49, 'limEmp' => 3],
            ['id' => 'pro', 'nome' => 'Profissional', 'preco' => 99, 'limEmp' => 10], ['id' => 'premium', 'nome' => 'Premium', 'preco' => 199, 'limEmp' => 0]];
}
function wsVazio() { return ['seq' => 1, 'empresas' => [], 'tickets' => [], 'scripts' => new stdClass(), 'settings' => ['tema' => 'auto', 'fonte' => 1, 'filtro' => '']]; }
function estadoVazio() { return ['v' => 2, 'clientes' => [], 'pagamentos' => [], 'log' => [], 'planos' => planosPadrao(), 'ws' => ['admin' => wsVazio()]]; }

/* garante a forma correta dos dados (e que "scripts" vazio continue sendo objeto {} e não lista []) */
function normalizar(&$r) {
    if (!is_array($r)) $r = estadoVazio();
    foreach (['clientes', 'pagamentos', 'log'] as $k) if (!isset($r[$k]) || !is_array($r[$k])) $r[$k] = [];
    if (empty($r['planos']) || !is_array($r['planos'])) $r['planos'] = planosPadrao();
    if (!isset($r['ws']) || !is_array($r['ws'])) $r['ws'] = [];
    if (!isset($r['ws']['admin'])) $r['ws']['admin'] = wsVazio();
    foreach ($r['ws'] as $k => $w) {
        if (!is_array($w)) { $w = wsVazio(); }
        foreach (['empresas', 'tickets'] as $f) if (!isset($w[$f]) || !is_array($w[$f])) $w[$f] = [];
        if (!isset($w['settings']) || !is_array($w['settings'])) $w['settings'] = ['tema' => 'auto', 'fonte' => 1, 'filtro' => ''];
        if (empty($w['scripts'])) $w['scripts'] = new stdClass();
        if (!isset($w['seq'])) $w['seq'] = 1;
        $r['ws'][$k] = $w;
    }
}
function wsHash($w) { unset($w['_h']); return md5(enc($w)); }
function carimbar(&$r) { foreach ($r['ws'] as $k => $w) { $r['ws'][$k]['_h'] = wsHash($w); } }
function contagem($root) {
    $cl = count($root['clientes'] ?? []); $at = 0; $ms = 0;
    foreach (($root['ws'] ?? []) as $w) foreach (($w['tickets'] ?? []) as $t) { $at++; $ms += count($t['msgs'] ?? []); }
    return [$cl, $at, $ms];
}
function achaCliente($root, $id) { foreach ($root['clientes'] as $c) if (($c['id'] ?? '') === $id) return $c; return null; }
function statusEf($c) { $st = $c['status'] ?? 'pendente'; if ($st === 'ativo' && !empty($c['validade']) && $c['validade'] < date('Y-m-d')) return 'vencido'; return $st; }
function bloqueio($c) {
    switch (statusEf($c)) {
        case 'pendente': return 'Seu cadastro está aguardando a liberação do administrador. Assim que liberado, você poderá entrar.';
        case 'suspenso': return 'Seu acesso está suspenso. Fale com o administrador.';
        case 'bloqueado': return 'Seu acesso foi bloqueado. Fale com o administrador.';
        case 'vencido': return 'Seu acesso venceu em ' . implode('/', array_reverse(explode('-', $c['validade']))) . '. Peça a renovação ao administrador.';
    }
    return null;
}
function addLog(&$root, $quem, $acao) {
    array_unshift($root['log'], ['t' => agora(), 'quem' => $quem, 'acao' => $acao]);
    $root['log'] = array_slice($root['log'], 0, 200);
}

/* o que cada papel pode enxergar */
function visao($root, $papel, $id) {
    if ($papel === 'admin') return $root;
    $c = achaCliente($root, $id);
    if ($c) { unset($c['senha'], $c['obs']); }
    $w = $root['ws'][$id] ?? wsVazio();
    return ['v' => 2, 'clientes' => $c ? [$c] : [], 'pagamentos' => [], 'log' => [], 'planos' => $root['planos'], 'ws' => [$id => $w]];
}
function hashes($root) { $h = []; foreach ($root['ws'] as $k => $w) $h[$k] = $w['_h'] ?? ''; return $h; }

/* ---------- limite de tentativas ---------- */
function falhas($pdo, $tipo, $janela) {
    $st = $pdo->prepare("SELECT COUNT(*) AS c FROM zb_tentativas WHERE tipo = ? AND chave = ? AND quando > (NOW() - INTERVAL " . (int)$janela . " SECOND)");
    $st->execute([$tipo, ip()]);
    return (int)$st->fetch()['c'];
}
function registrar($pdo, $tipo) {
    $pdo->prepare("INSERT INTO zb_tentativas (tipo, chave) VALUES (?, ?)")->execute([$tipo, ip()]);
    $pdo->exec("DELETE FROM zb_tentativas WHERE quando < (NOW() - INTERVAL 1 DAY)");
}

/* ---------- banco: leitura e gravação ---------- */
function lerEstado($pdo) {
    $r = $pdo->query("SELECT `json`, `atualizado` FROM zb_estado WHERE id = 1")->fetch();
    $root = $r ? json_decode($r['json'], true) : estadoVazio();
    normalizar($root);
    return [$root, $r ? (int)$r['atualizado'] : 0];
}

/* grava o estado completo + tabelas organizadas (dentro de uma transação já aberta) */
function gravar($pdo, $root, $manual = false, $guardarAnterior = false) {
    global $MAX_BACKUPS, $INTERVALO_BACKUP;
    normalizar($root);
    $json = enc($root);
    $agora = agora();

    // proteção: se o novo estado tem MENOS clientes/mensagens, guarda o anterior antes
    $prev = $pdo->query("SELECT `json` FROM zb_estado WHERE id = 1")->fetch();
    if ($prev) {
        $po = json_decode($prev['json'], true);
        if (is_array($po)) {
            [$pc, $pa, $pm] = contagem($po); [$nc, $na, $nm] = contagem($root);
            if ($guardarAnterior || $nc < $pc || $nm < $pm) {
                $pdo->prepare("INSERT INTO zb_backups (origem,clientes,atendimentos,mensagens,tamanho_kb,`json`) VALUES (?,?,?,?,?,?)")
                    ->execute([$guardarAnterior ? 'antes de restaurar' : 'antes de apagar', $pc, $pa, $pm, (int)ceil(strlen($prev['json']) / 1024), $prev['json']]);
            }
        }
    }

    $pdo->prepare("INSERT INTO zb_estado (`id`, `json`, `atualizado`, `atualizado_em`) VALUES (1, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE `json` = VALUES(`json`), `atualizado` = VALUES(`atualizado`), `atualizado_em` = NOW()")
        ->execute([$json, $agora]);

    foreach (['zb_planos','zb_clientes','zb_pagamentos','zb_log_acessos','zb_configuracoes','zb_empresas','zb_roteiros','zb_atendimentos','zb_mensagens'] as $t) $pdo->exec("DELETE FROM $t");

    $q = $pdo->prepare("INSERT IGNORE INTO zb_planos (id, nome, preco, limite_empresas) VALUES (?,?,?,?)");
    foreach ($root['planos'] as $p) $q->execute([s($p['id'] ?? '',40), s($p['nome'] ?? '',80), (float)($p['preco'] ?? 0), (int)($p['limEmp'] ?? 0)]);

    $q = $pdo->prepare("INSERT IGNORE INTO zb_clientes (id,nome,responsavel,email,telefone,atividade,usuario,senha,plano_id,status,validade,criado_em,liberado_em,ultimo_acesso,acessos,obs) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($root['clientes'] as $c) $q->execute([s($c['id'] ?? '',40), s($c['nome'] ?? '',160), s($c['responsavel'] ?? '',160), s($c['email'] ?? '',190), s($c['telefone'] ?? '',40), s($c['atividade'] ?? '',40), s($c['usuario'] ?? '',80), s($c['senha'] ?? '',120), s($c['plano'] ?? '',40), s($c['status'] ?? 'pendente',20), dd($c['validade'] ?? ''), dt($c['criado'] ?? 0), dt($c['liberadoEm'] ?? 0), dt($c['ultimoAcesso'] ?? 0), (int)($c['acessos'] ?? 0), s($c['obs'] ?? '')]);

    $q = $pdo->prepare("INSERT IGNORE INTO zb_pagamentos (id,cliente_id,valor,`data`,obs) VALUES (?,?,?,?,?)");
    foreach ($root['pagamentos'] as $p) $q->execute([s($p['id'] ?? '',40), s($p['clienteId'] ?? '',40), (float)($p['valor'] ?? 0), dd($p['data'] ?? ''), s($p['obs'] ?? '',255)]);

    $q = $pdo->prepare("INSERT IGNORE INTO zb_log_acessos (quando,quem,acao) VALUES (?,?,?)");
    foreach (array_reverse($root['log']) as $l) $q->execute([dt($l['t'] ?? 0), s($l['quem'] ?? '',190), s($l['acao'] ?? '',255)]);

    $qc = $pdo->prepare("INSERT IGNORE INTO zb_configuracoes (ambiente_id,tema,fonte,filtro,proximo_protocolo) VALUES (?,?,?,?,?)");
    $qe = $pdo->prepare("INSERT IGNORE INTO zb_empresas (ambiente_id,id,nome,atividade,telefone,responsavel,horario,cidade,boas_vindas,obs) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $qr = $pdo->prepare("INSERT IGNORE INTO zb_roteiros (ambiente_id,atividade,`json`) VALUES (?,?,?)");
    $qa = $pdo->prepare("INSERT IGNORE INTO zb_atendimentos (ambiente_id,id,protocolo,empresa_id,cliente,telefone,assunto,detalhe,status,etapa,nota,criado_em,resolvido_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $qm = $pdo->prepare("INSERT IGNORE INTO zb_mensagens (ambiente_id,atendimento_id,`ordem`,`de`,`texto`,enviada_em) VALUES (?,?,?,?,?,?)");
    foreach ($root['ws'] as $amb => $w) {
        $amb = s($amb, 40);
        $cfg = $w['settings'] ?? [];
        $qc->execute([$amb, s($cfg['tema'] ?? 'auto',10), (float)($cfg['fonte'] ?? 1), s($cfg['filtro'] ?? '',40), (int)($w['seq'] ?? 1)]);
        foreach ($w['empresas'] as $e) $qe->execute([$amb, s($e['id'] ?? '',40), s($e['nome'] ?? '',160), s($e['atividade'] ?? '',40), s($e['telefone'] ?? '',40), s($e['responsavel'] ?? '',160), s($e['horario'] ?? '',160), s($e['cidade'] ?? '',120), s($e['boasVindas'] ?? ''), s($e['obs'] ?? '')]);
        foreach ((array)$w['scripts'] as $k => $sc) $qr->execute([$amb, s($k,40), enc($sc)]);
        foreach ($w['tickets'] as $t) {
            $tid = s($t['id'] ?? '', 40);
            $qa->execute([$amb, $tid, s($t['protocolo'] ?? '',30), s($t['empresaId'] ?? '',40), s($t['cliente'] ?? '',160), s($t['telefone'] ?? '',40), s($t['opcao'] ?? '',255), s($t['detalhe'] ?? ''), s($t['status'] ?? 'bot',20), s($t['etapa'] ?? '',20), (int)($t['nota'] ?? 0), dt($t['criado'] ?? 0), dt($t['resolvido'] ?? 0)]);
            foreach (($t['msgs'] ?? []) as $i => $m) $qm->execute([$amb, $tid, $i + 1, s($m['de'] ?? '',20), s($m['txt'] ?? ''), dt($m['t'] ?? 0)]);
        }
    }

    $ult = $pdo->query("SELECT UNIX_TIMESTAMP(MAX(criado_em)) AS u FROM zb_backups")->fetch();
    if ($manual || !$ult['u'] || (time() - (int)$ult['u']) >= $INTERVALO_BACKUP) {
        [$cl, $at, $ms] = contagem($root);
        $pdo->prepare("INSERT INTO zb_backups (origem,clientes,atendimentos,mensagens,tamanho_kb,`json`) VALUES (?,?,?,?,?,?)")
            ->execute([$manual ? 'manual' : 'automatico', $cl, $at, $ms, (int)ceil(strlen($json) / 1024), $json]);
        $pdo->exec("DELETE FROM zb_backups WHERE id <= (SELECT m FROM (SELECT CAST(MAX(id) AS SIGNED) - " . (int)$MAX_BACKUPS . " AS m FROM zb_backups) x)");
    }
    return $agora;
}

/* lê, altera e grava de forma atômica. $fn recebe o estado e devolve [novoEstado, extra] (ou null para não gravar) */
function mutar($pdo, $fn, $manual = false, $guardarAnterior = false) {
    $pdo->beginTransaction();
    $r = $pdo->query("SELECT `json` FROM zb_estado WHERE id = 1 FOR UPDATE")->fetch();
    $root = $r ? json_decode($r['json'], true) : estadoVazio();
    normalizar($root);
    $res = $fn($root);
    if ($res === null) { $pdo->commit(); return null; }
    [$novo, $extra] = $res;
    normalizar($novo);
    carimbar($novo);
    $t = gravar($pdo, $novo, $manual, $guardarAnterior);
    $pdo->commit();
    return ['root' => $novo, 't' => $t, 'extra' => $extra];
}

/* ---------- junção de dados (vários aparelhos gravando ao mesmo tempo) ---------- */
function mergeWs($srv, $sub, &$alt) {
    $out = $sub;
    $idx = [];
    foreach ($out['tickets'] as $i => $t) $idx[$t['id'] ?? ''] = $i;
    foreach ($srv['tickets'] as $t) {
        $id = $t['id'] ?? '';
        if (!isset($idx[$id])) { $out['tickets'][] = $t; $alt = true; }
        elseif (count($t['msgs'] ?? []) > count($out['tickets'][$idx[$id]]['msgs'] ?? [])) { $out['tickets'][$idx[$id]] = $t; $alt = true; }
    }
    usort($out['tickets'], function ($a, $b) { return ($b['criado'] ?? 0) <=> ($a['criado'] ?? 0); });
    $out['seq'] = max((int)($srv['seq'] ?? 1), (int)($out['seq'] ?? 1));
    return $out;
}
function sanearWs($w) {
    $o = wsVazio();
    $o['seq'] = max(1, (int)($w['seq'] ?? 1));
    $o['empresas'] = array_slice(array_values(array_filter((array)($w['empresas'] ?? []), 'is_array')), 0, 500);
    $o['tickets'] = array_slice(array_values(array_filter((array)($w['tickets'] ?? []), function ($t) { return is_array($t) && !empty($t['id']); })), 0, 20000);
    foreach ($o['tickets'] as $i => $t) { $o['tickets'][$i]['msgs'] = array_slice(array_values((array)($t['msgs'] ?? [])), 0, 3000); }
    $sc = $w['scripts'] ?? [];
    $o['scripts'] = (is_array($sc) && $sc) ? $sc : new stdClass();
    $st = (array)($w['settings'] ?? []);
    $o['settings'] = ['tema' => in_array($st['tema'] ?? '', ['auto', 'light', 'dark'], true) ? $st['tema'] : 'auto', 'fonte' => min(2, max(0.8, (float)($st['fonte'] ?? 1))), 'filtro' => s($st['filtro'] ?? '', 40)];
    $o['_h'] = s($w['_h'] ?? '', 40);
    return $o;
}

/* ====================== ações ====================== */
$acao = $_GET['acao'] ?? '';
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = []; }

try {
    /* ---- públicas ---- */
    if ($acao === 'sessao') {
        $papel = $_SESSION['papel'] ?? null;
        if (!$papel) resp(['ok' => true, 'papel' => null]);
        $a = $pdo->query("SELECT senha_hash FROM zb_admin WHERE id = 1")->fetch();
        resp(['ok' => true, 'papel' => $papel, 'id' => $_SESSION['id'] ?? null, 'senhaPadrao' => $papel === 'admin' && password_verify($SENHA_PADRAO, $a['senha_hash'])]);
    }

    if ($acao === 'login') {
        if (falhas($pdo, 'login', 900) >= 8) throw new ErroApi('Muitas tentativas de entrada. Aguarde alguns minutos e tente de novo.', 429);
        $u = mb_strtolower(trim(s($in['usuario'] ?? '', 80)));
        $p = s($in['senha'] ?? '', 200);
        $falha = 'Usuário ou senha incorretos. Confira e tente de novo.';
        $a = $pdo->query("SELECT usuario, senha_hash FROM zb_admin WHERE id = 1")->fetch();
        if ($u !== '' && $u === mb_strtolower($a['usuario'])) {
            if (!password_verify($p, $a['senha_hash'])) { registrar($pdo, 'login'); throw new ErroApi($falha, 401); }
            session_regenerate_id(true);
            $_SESSION = ['papel' => 'admin', 'id' => null];
            mutar($pdo, function ($root) { addLog($root, 'Administrador Dev', 'Entrou no sistema'); return [$root, null]; });
            resp(['ok' => true, 'papel' => 'admin', 'senhaPadrao' => password_verify($SENHA_PADRAO, $a['senha_hash'])]);
        }
        [$root] = lerEstado($pdo);
        $achado = null;
        foreach ($root['clientes'] as $c) if ($u !== '' && mb_strtolower($c['usuario'] ?? '') === $u && hash_equals((string)($c['senha'] ?? ''), $p)) { $achado = $c; break; }
        if (!$achado) { registrar($pdo, 'login'); throw new ErroApi($falha, 401); }
        $b = bloqueio($achado);
        if ($b) {
            mutar($pdo, function ($root) use ($achado) { addLog($root, $achado['nome'], 'Tentativa de entrada negada (' . statusEf($achado) . ')'); return [$root, null]; });
            throw new ErroApi($b, 403);
        }
        session_regenerate_id(true);
        $_SESSION = ['papel' => 'cliente', 'id' => $achado['id']];
        $cid = $achado['id'];
        mutar($pdo, function ($root) use ($cid) {
            foreach ($root['clientes'] as $i => $c) if ($c['id'] === $cid) { $root['clientes'][$i]['ultimoAcesso'] = agora(); $root['clientes'][$i]['acessos'] = (int)($c['acessos'] ?? 0) + 1; addLog($root, $c['nome'], 'Entrou no sistema'); }
            return [$root, null];
        });
        resp(['ok' => true, 'papel' => 'cliente', 'id' => $cid]);
    }

    if ($acao === 'cadastrar') {
        if (falhas($pdo, 'cadastro', 3600) >= 5) throw new ErroApi('Muitas solicitações deste aparelho. Tente novamente mais tarde.', 429);
        $o = [
            'nome' => trim(s($in['nome'] ?? '', 160)), 'responsavel' => trim(s($in['responsavel'] ?? '', 160)), 'email' => trim(s($in['email'] ?? '', 190)),
            'telefone' => trim(s($in['telefone'] ?? '', 40)), 'atividade' => s($in['atividade'] ?? '', 40), 'usuario' => mb_strtolower(trim(s($in['usuario'] ?? '', 80))), 'senha' => s($in['senha'] ?? '', 100),
        ];
        if ($o['nome'] === '') throw new ErroApi('Informe o nome do negócio.');
        if ($o['responsavel'] === '') throw new ErroApi('Informe o nome do responsável.');
        if (!preg_match('/^\S+@\S+\.\S+$/', $o['email'])) throw new ErroApi('Informe um e-mail válido.');
        if (strlen(preg_replace('/\D/', '', $o['telefone'])) < 10) throw new ErroApi('Informe o WhatsApp com DDD.');
        if (!preg_match('/^[a-z_]{2,30}$/', $o['atividade'])) throw new ErroApi('Escolha a atividade exercida.');
        if (!preg_match('/^[a-z0-9._-]{3,}$/', $o['usuario'])) throw new ErroApi('Usuário inválido. Use letras minúsculas, números, ponto, hífen ou sublinhado (mínimo 3).');
        if (strlen($o['senha']) < 4) throw new ErroApi('A senha precisa ter pelo menos 4 caracteres.');
        $adm = $pdo->query("SELECT usuario FROM zb_admin WHERE id = 1")->fetch();
        if ($o['usuario'] === mb_strtolower($adm['usuario'])) throw new ErroApi('Esse usuário já está em uso. Escolha outro.');
        registrar($pdo, 'cadastro');
        mutar($pdo, function ($root) use ($o) {
            foreach ($root['clientes'] as $c) if (mb_strtolower($c['usuario'] ?? '') === $o['usuario']) throw new ErroApi('Esse usuário já está em uso. Escolha outro.');
            $id = novoId();
            $n = $o + ['id' => $id, 'plano' => $root['planos'][0]['id'] ?? 'teste', 'status' => 'pendente', 'criado' => agora(), 'liberadoEm' => 0, 'validade' => '', 'ultimoAcesso' => 0, 'acessos' => 0, 'obs' => ''];
            $root['clientes'][] = $n;
            $w = wsVazio(); $w['settings']['filtro'] = $o['atividade'];
            $w['empresas'][] = ['id' => novoId(), 'nome' => $o['nome'], 'atividade' => $o['atividade'], 'telefone' => $o['telefone'], 'responsavel' => $o['responsavel'], 'horario' => 'Seg a Sex, 8h às 18h', 'cidade' => '', 'boasVindas' => '', 'obs' => ''];
            $root['ws'][$id] = $w;
            addLog($root, $o['nome'], 'Solicitou cadastro');
            return [$root, null];
        });
        resp(['ok' => true]);
    }

    if ($acao === 'logout') { $_SESSION = []; session_destroy(); resp(['ok' => true]); }

    /* ---- daqui para baixo: só com login ---- */
    $papel = $_SESSION['papel'] ?? null;
    $uid = $_SESSION['id'] ?? null;
    if (!$papel) throw new ErroApi('Sessão expirada. Entre novamente.', 401);
    if ($papel === 'cliente') {
        [$r0] = lerEstado($pdo);
        $c0 = achaCliente($r0, $uid);
        $b = $c0 ? bloqueio($c0) : 'Cadastro não encontrado.';
        if ($b) { $_SESSION = []; session_destroy(); throw new ErroApi($b, 403, ['bloqueado' => true]); }
    }
    $admin = $papel === 'admin';
    function soAdmin($admin) { if (!$admin) throw new ErroApi('Acesso negado.', 403); }

    if ($acao === 'carregar' || $acao === 'versao') {
        [$root, $t] = lerEstado($pdo);
        carimbar($root);
        $vi = visao($root, $papel, $uid);
        $v = md5(enc($vi));
        if ($acao === 'versao') resp(['ok' => true, 'v' => $v]);
        resp(['ok' => true, 'v' => $v, 't' => $t, 'estado' => $vi]);
    }

    if ($acao === 'salvar') {
        $alt = false;
        if ($admin) {
            $sub = $in['estado'] ?? null;
            if (!is_array($sub) || !isset($sub['ws']) || !is_array($sub['clientes'] ?? null)) throw new ErroApi('Dados inválidos.');
            $base = (int)($in['base'] ?? 0);
            normalizar($sub);
            $res = mutar($pdo, function ($srv) use ($sub, $base, &$alt) {
                $out = $sub;
                // clientes: o que o admin enviou + cadastros novos feitos depois da última leitura dele
                $srvPorId = []; foreach ($srv['clientes'] as $c) $srvPorId[$c['id'] ?? ''] = $c;
                $ids = [];
                foreach ($out['clientes'] as $i => $c) {
                    $id = $c['id'] ?? ''; $ids[$id] = true;
                    if (isset($srvPorId[$id])) {
                        $out['clientes'][$i]['acessos'] = max((int)($c['acessos'] ?? 0), (int)($srvPorId[$id]['acessos'] ?? 0));
                        $out['clientes'][$i]['ultimoAcesso'] = max((int)($c['ultimoAcesso'] ?? 0), (int)($srvPorId[$id]['ultimoAcesso'] ?? 0));
                    }
                }
                foreach ($srv['clientes'] as $c) if (!isset($ids[$c['id'] ?? '']) && (int)($c['criado'] ?? 0) > $base) { $out['clientes'][] = $c; $ids[$c['id']] = true; $alt = true; }
                // registro de acessos: junta os dois lados
                $vistos = []; foreach ($out['log'] as $l) $vistos[($l['t'] ?? 0) . '|' . ($l['quem'] ?? '') . '|' . ($l['acao'] ?? '')] = true;
                foreach ($srv['log'] as $l) { $k = ($l['t'] ?? 0) . '|' . ($l['quem'] ?? '') . '|' . ($l['acao'] ?? ''); if (!isset($vistos[$k])) { $out['log'][] = $l; $alt = true; } }
                usort($out['log'], function ($a, $b) { return ($b['t'] ?? 0) <=> ($a['t'] ?? 0); });
                $out['log'] = array_slice($out['log'], 0, 200);
                // ambientes
                $ws = [];
                foreach ($out['ws'] as $k => $w) {
                    if ($k !== 'admin' && !isset($ids[$k])) continue;
                    if ($k !== 'admin' && isset($srv['ws'][$k]) && ($w['_h'] ?? '') !== ($srv['ws'][$k]['_h'] ?? '')) $w = mergeWs($srv['ws'][$k], $w, $alt);
                    $ws[$k] = $w;
                }
                foreach ($srv['ws'] as $k => $w) if (!isset($ws[$k]) && isset($ids[$k])) { $ws[$k] = $w; $alt = true; }
                $out['ws'] = $ws;
                return [$out, null];
            }, !empty($in['manual']));
        } else {
            $sub = $in['ws'] ?? null;
            if (!is_array($sub)) throw new ErroApi('Dados inválidos.');
            $res = mutar($pdo, function ($srv) use ($sub, $uid, &$alt) {
                $c = achaCliente($srv, $uid);
                if (!$c) throw new ErroApi('Cadastro não encontrado.', 403);
                $w = sanearWs($sub);
                $atual = $srv['ws'][$uid] ?? wsVazio();
                if ($w['_h'] === ($atual['_h'] ?? 'x')) $novo = $w; else $novo = mergeWs($atual, $w, $alt);
                foreach ($srv['planos'] as $pl) if (($pl['id'] ?? '') === ($c['plano'] ?? '') && (int)($pl['limEmp'] ?? 0) > 0) $novo['empresas'] = array_slice($novo['empresas'], 0, (int)$pl['limEmp']);
                $novo['settings']['filtro'] = $c['atividade'] ?? '';
                $srv['ws'][$uid] = $novo;
                return [$srv, null];
            });
        }
        $vi = visao($res['root'], $papel, $uid);
        $out = ['ok' => true, 't' => $res['t'], 'v' => md5(enc($vi))];
        if ($alt) $out['estado'] = $vi; else $out['hs'] = hashes($vi);
        resp($out);
    }

    if ($acao === 'trocar_senha') {
        if ($admin) throw new ErroApi('Use a troca de senha do administrador.', 400);
        $at = s($in['atual'] ?? '', 200); $nv = s($in['nova'] ?? '', 100);
        if (strlen($nv) < 4) throw new ErroApi('A nova senha precisa ter pelo menos 4 caracteres.');
        if (falhas($pdo, 'senha', 900) >= 8) throw new ErroApi('Muitas tentativas. Aguarde alguns minutos.', 429);
        [$r1] = lerEstado($pdo);
        $c1 = achaCliente($r1, $uid);
        if (!$c1 || !hash_equals((string)($c1['senha'] ?? ''), $at)) { registrar($pdo, 'senha'); throw new ErroApi('A senha atual está incorreta.', 400); }
        mutar($pdo, function ($root) use ($uid, $nv) {
            foreach ($root['clientes'] as $i => $c) if ($c['id'] === $uid) { $root['clientes'][$i]['senha'] = $nv; addLog($root, $c['nome'], 'Trocou a senha'); }
            return [$root, null];
        });
        resp(['ok' => true]);
    }

    /* ---- somente administrador ---- */
    soAdmin($admin);

    if ($acao === 'trocar_senha_admin') {
        $at = s($in['atual'] ?? '', 200); $nv = s($in['nova'] ?? '', 100);
        if (strlen($nv) < 4) throw new ErroApi('A nova senha precisa ter pelo menos 4 caracteres.');
        if (falhas($pdo, 'senha', 900) >= 8) throw new ErroApi('Muitas tentativas. Aguarde alguns minutos.', 429);
        $a = $pdo->query("SELECT senha_hash FROM zb_admin WHERE id = 1")->fetch();
        if (!password_verify($at, $a['senha_hash'])) { registrar($pdo, 'senha'); throw new ErroApi('A senha atual está incorreta.', 400); }
        $pdo->prepare("UPDATE zb_admin SET senha_hash = ? WHERE id = 1")->execute([password_hash($nv, PASSWORD_DEFAULT)]);
        resp(['ok' => true]);
    }

    if ($acao === 'backups') {
        $l = $pdo->query("SELECT id, DATE_FORMAT(criado_em, '%d/%m/%Y %H:%i') AS quando, origem, clientes, atendimentos, mensagens, tamanho_kb FROM zb_backups ORDER BY id DESC LIMIT 30")->fetchAll();
        resp(['ok' => true, 'backups' => $l]);
    }

    if ($acao === 'restaurar') {
        $st = $pdo->prepare("SELECT `json` FROM zb_backups WHERE id = ?");
        $st->execute([(int)($in['id'] ?? 0)]);
        $r = $st->fetch();
        if (!$r) throw new ErroApi('Backup não encontrado.', 404);
        $bk = json_decode($r['json'], true);
        if (!is_array($bk) || !isset($bk['ws'])) throw new ErroApi('Backup inválido.');
        mutar($pdo, function ($root) use ($bk) { return [$bk, null]; }, false, true);
        resp(['ok' => true]);
    }

    throw new ErroApi('Ação desconhecida.', 400);
} catch (ErroApi $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    resp(['ok' => false, 'erro' => $e->getMessage()] + $e->extra, $e->getCode() ?: 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    resp(['ok' => false, 'erro' => 'Erro no banco: ' . $e->getMessage() . ' (o arquivo zapbot_banco.sql já foi executado no phpMyAdmin?)'], 500);
}
