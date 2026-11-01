<?php
/**
 * ============================================================
 *  若琳AI送（销）货单管理系统 —— 本地单机版（ticket.php）
 * ============================================================
 *  功能：新增/打印送（销）货单、历史单据管理（查看/删除）、
 *        搜索、CSV 导出、票据 HTML 留存、资源管理器定位文件
 *  运行环境：PHP 7.4+（无需数据库、无需第三方库/插件）
 *  入口地址：http://127.0.0.1:8000/ticket.php
 *  说明：开票后信息锁定不可更改，仅可删除；品牌名称/Logo 可在页面「✎ 编辑」修改
 *
 *  部署说明请查看同目录下《部署说明.md》
 * ============================================================
 */

date_default_timezone_set('Asia/Shanghai');
header("Content-type:text/html;charset=utf-8");
// 页面本身禁止缓存：修改「系统名称 / Logo」后，浏览器标签上的文字与图标立刻刷新
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// ============================================================
//  全局配置（部署 / 发布前请按需修改）
// ============================================================
$CONFIG = [
    'brand'       => '若琳AI',           // 品牌/送货系统名称默认值（可在页面「编辑」里修改，票据/登录同步生效）
    'ticket_name' => '送（销）货单',     // 单据名称
    'maker'       => 'xxx',             // 制票人默认值（开票时可在表单中随意修改）
    'deliverer'   => 'xxx',             // 送货人默认值（开票时可在表单中随意修改）
    'unit'        => '若琳',            // 送货单位兜底值：正常取「送货系统名称」，仅当品牌为空时才用它
];

// 品牌设置文件：页面「编辑」修改的名称/Logo 保存在这里，重启/刷新后依然生效
$settingsFile = __DIR__ . '/settings.json';
if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode(['brand' => $CONFIG['brand']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
$settings = json_decode(file_get_contents($settingsFile), true);
if (is_array($settings) && isset($settings['brand']) && trim((string)$settings['brand']) !== '') {
    $CONFIG['brand'] = trim((string)$settings['brand']);
}

// ------------------------------------------------------------
//  Logo 资源（log.png）：版本号 = 文件修改时间
//  作用：换图后浏览器标签图标（favicon）和页面 Logo 立即换成新图，
//        不会被浏览器缓存里的旧图顶住。
// ------------------------------------------------------------
$logoPath = __DIR__ . '/log.png';
$logoVer  = file_exists($logoPath) ? (int)filemtime($logoPath) : 0;
$logoMime = 'image/png';
if ($logoVer > 0) {
    $logoInfo = @getimagesize($logoPath);
    if (is_array($logoInfo) && !empty($logoInfo['mime'])) { $logoMime = $logoInfo['mime']; }
}
$logoUrl = 'log.png?v=' . $logoVer;

// 数据文件：票据数据、常用人员、票据留存目录
$jsonFile    = __DIR__ . '/ticket_data.json';
$peopleFile  = __DIR__ . '/people.json';
$ticketRoot  = __DIR__ . '/票据留存';
if (!file_exists($ticketRoot)) { mkdir($ticketRoot, 0755, true); }

// 初始化数据文件
if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
$list = json_decode(file_get_contents($jsonFile), true);
if (!$list) $list = [];

// 初始化常用人员名单（制票人 / 送货人，可长期积累）
if (!file_exists($peopleFile)) {
    file_put_contents($peopleFile, json_encode(['makers' => [], 'deliverers' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
$people = json_decode(file_get_contents($peopleFile), true);
if (!is_array($people)) $people = ['makers' => [], 'deliverers' => []];
if (!isset($people['makers']) || !is_array($people['makers']))     $people['makers'] = [];
if (!isset($people['deliverers']) || !is_array($people['deliverers'])) $people['deliverers'] = [];

// ------------------------------------------------------------
// 搜索过滤（按送货单号 / 收货单位）
// ------------------------------------------------------------
$searchKw = isset($_GET['kw']) ? trim($_GET['kw']) : '';
$displayList = $list;
if ($searchKw !== '') {
    $displayList = array_filter($list, function ($item) use ($searchKw) {
        return stripos((string)($item['ticket_no'] ?? ''), $searchKw) !== false
            || stripos((string)($item['buyer'] ?? ''), $searchKw) !== false;
    });
}

// 按「开票时间」从高到低排序（导入的旧票据同样按票面开票时间归位），
// 时间相同则按单号（新单在前），保证顺序稳定、与存单/导入时间无关
$displayList = array_values($displayList);
usort($displayList, 'cmpByInvoiceTimeDesc');

// 历史列表默认只展示最近 10 张；输入单号/收货单位搜索时展示全部匹配结果
define('HISTORY_PAGE_SIZE', 10);
$historyTotal = count($list);          // 单据总数（统计用）
$matchedCount = count($displayList);   // 搜索命中的数量
if ($searchKw === '') {
    $displayList = array_slice($displayList, 0, HISTORY_PAGE_SIZE);
}

// 已开票信息一经保存即不可修改（防止店家篡改票据）；可删除，需重新开票请先删除旧单

/**
 * 人民币金额转中文大写（用于票据「合计」栏）
 * 例：133.00 → 壹佰叁拾叁元整
 */
function rmbUpper($num)
{
    $num = floatval($num);
    if ($num == 0) return '零元整';
    $cnums    = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
    $units    = ['', '拾', '佰', '仟'];
    $bigUnits = ['', '万', '亿', '万亿'];
    $int = intval($num);
    $dec = round(($num - $int) * 100);
    $str = '';
    if ($int > 0) {
        $intStr = (string)$int;
        $len = strlen($intStr);
        $zeroFlag = false;
        $lastBigUnit = '';
        for ($i = 0; $i < $len; $i++) {
            $n = (int)$intStr[$i];
            $p = $len - $i - 1;
            $g = $p % 4;
            $b = intval($p / 4);
            if ($n == 0) {
                $zeroFlag = true;
            } else {
                if ($zeroFlag) { $str .= '零'; $zeroFlag = false; }
                $str .= $cnums[$n] . $units[$g];
            }
            // 每 4 位一组结束时加大单位
            if ($g == 0 && $b > 0) {
                $str = rtrim($str, '零');
                // 只在当前大单位和上次不同时才加（避免万万/亿亿）
                if ($bigUnits[$b] != $lastBigUnit) {
                    $str .= $bigUnits[$b];
                    $lastBigUnit = $bigUnits[$b];
                }
            }
        }
        $str = rtrim($str, '零');
        $str .= '元';
    }
    if ($dec == 0) {
        if ($int == 0) return '零元整';
        $str .= '整';
    } else {
        $j = intval($dec / 10);
        $f = $dec % 10;
        if ($j > 0) $str .= $cnums[$j] . '角';
        if ($f > 0) $str .= $cnums[$f] . '分';
        else if ($j > 0) $str .= '整';
    }
    return $str;
}

/**
 * 生成当日序号编号（取当日最大序号 +1，避免并发/重复）
 * 例：2026103100001
 */
function genTicketNo(&$list)
{
    $today = date('Ymd');
    $maxSeq = 0;
    foreach ($list as $item) {
        if (strpos($item['ticket_no'], $today) === 0) {
            $seqPart = substr($item['ticket_no'], 8);
            $seq = intval($seqPart);
            if ($seq > $maxSeq) $maxSeq = $seq;
        }
    }
    $newSeq = $maxSeq + 1;
    return $today . str_pad($newSeq, 5, '0', STR_PAD_LEFT);
}

/** 去除 HTML 标签并转回普通文本（票据解析用） */
function clean($s)
{
    return trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'));
}

/**
 * 解析一张票据 HTML 文件内容为单据记录（历史票据导入用，与 import_history.php 同规则）
 * @param string $html         票据 HTML 内容
 * @param string $fallbackTime 解析不到时间时使用的兜底时间（Y-m-d H:i:s）
 * @return array|null 解析失败（无法识别单号）返回 null
 */
function parseTicketHtml($html, $fallbackTime)
{
    // 编码兜底：非 UTF-8 的旧文件尝试转码
    if (function_exists('mb_check_encoding') && !mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'GB18030');
    }
    // 送（销）货单号（兼容旧版「送货单号」标签）
    if (!preg_match('/(?:送（销）货单号|送货单号)：(\d+)/u', $html, $mNo)) {
        return null;
    }
    $ticketNo = $mNo[1];

    // 开票时间：优先取票面时间，否则用兜底时间
    $invoiceTime = $fallbackTime;
    $dateCn = '';
    $timeUnknown = false;
    if (preg_match('/<span class="right">开票时间：([\d\- :]+)<\/span>/u', $html, $mT)) {
        $invoiceTime = trim($mT[1]);
    } elseif (preg_match('/<span class="right">(\d{4}年\d{1,2}月\d{1,2}日)<\/span>/u', $html, $mD)) {
        // 旧票只记了日期、没记具体时间：标记出来，统计/排序按票面日期归位，不按导入时间
        $dateCn = $mD[1];
        $timeUnknown = true;
    }
    if ($dateCn === '') $dateCn = date('Y年m月d日', strtotime($invoiceTime));

    $buyer    = preg_match('/收货单位：(.+?)<\/span>/u', $html, $mB) ? clean($mB[1]) : '';
    $address  = preg_match('/地址、电话：(.*?)<\/span>/u', $html, $mA) ? clean($mA[1]) : '';
    $maker    = preg_match('/制票：(.+?)<\/span>/u', $html, $mM) ? clean($mM[1]) : '';
    $deliverer = preg_match('/送货人：(.+?)<\/span>/u', $html, $mD2) ? clean($mD2[1]) : '';

    // 品牌（商户名称）：从标题提取，开票后锁定，不随系统名称变化
    $brand = '';
    if (preg_match('/<h1>(.*?)送（销）货单<\/h1>/u', $html, $mH1)) {
        $brand = clean($mH1[1]);
    }

    // 明细行
    $items = [];
    if (preg_match('/<tbody>(.*?)<\/tbody>/su', $html, $mBody)) {
        if (preg_match_all('/<tr>(.*?)<\/tr>/su', $mBody[1], $rows)) {
            foreach ($rows[1] as $row) {
                if (!preg_match_all('/<td>(.*?)<\/td>/su', $row, $cells)) continue;
                $c = array_map('clean', $cells[1]);
                if (count($c) < 6) continue;
                $name = $c[0];
                if ($name === '') continue; // 跳过空行
                $items[] = [
                    'name'   => $name,
                    'unit'   => $c[1],
                    'qty'    => (float)str_replace(',', '', $c[2]),
                    'price'  => (float)str_replace(',', '', $c[3]),
                    'amount' => round((float)str_replace(',', '', $c[4]), 2),
                    'note'   => $c[5],
                ];
            }
        }
    }

    $total = 0.0;
    if (preg_match('/class="rmb">￥([\d,]+\.\d{2})<\/td>/u', $html, $mT2)) {
        $total = (float)str_replace(',', '', $mT2[1]);
    }
    $totalCn = preg_match('/class="num-unit">([^<]+)<\/td>/u', $html, $mC) ? clean($mC[1]) : '';

    return [
        'ticket_no'       => $ticketNo,
        'create_time'     => $fallbackTime,
        'invoice_time'    => $invoiceTime,
        'date'            => $dateCn,
        'invoice_date_cn' => $dateCn,
        'buyer'           => $buyer,
        'address'         => $address,
        'maker'           => $maker,
        'deliverer'       => $deliverer,
        'items'           => $items,
        'total'           => $total,
        'total_cn'        => $totalCn,
        'brand'           => $brand,
        'time_unknown'    => $timeUnknown,
    ];
}

/**
 * 单据的「开票日期」（Y-m-d）
 * 只认票面信息：票面只有年月日时按该日期归位，绝不用导入/存单时间顶替，
 * 这样导入的旧票不会被算进今天。
 */
function ticketInvDay(array $t)
{
    $cn = trim((string)($t['invoice_date_cn'] ?? ($t['date'] ?? '')));
    if (preg_match('/(\d{4})\D+(\d{1,2})\D+(\d{1,2})\D*/u', $cn, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    $d = substr((string)($t['invoice_time'] ?? ''), 0, 10);
    if ($d === '') $d = substr((string)($t['create_time'] ?? ''), 0, 10);
    return $d;
}

/** 开票时间展示用：票面只有日期时提示“原票未记具体时间”，不展示导入时间冒充开票时间 */
function ticketInvShow(array $t)
{
    $day = ticketInvDay($t);
    $raw = trim((string)($t['invoice_time'] ?? ''));
    $unknown = !empty($t['time_unknown']) || ($raw !== '' && substr($raw, 0, 10) !== $day);
    if ($unknown) return ($day !== '' ? $day : $raw) . '（原票未记时间）';
    return $raw !== '' ? $raw : $day;
}

/** 排序比较器：按票面开票时间从高到低，同一时间按单号倒序（稳定、可复用） */
function cmpByInvoiceTimeDesc($a, $b)
{
    $ta = strtotime(ticketInvDay($a) . ' ' . (substr((string)($a['invoice_time'] ?? ''), 11, 8) ?: '00:00:00')) ?: 0;
    $tb = strtotime(ticketInvDay($b) . ' ' . (substr((string)($b['invoice_time'] ?? ''), 11, 8) ?: '00:00:00')) ?: 0;
    if ($ta === $tb) {
        return strcmp((string)($b['ticket_no'] ?? ''), (string)($a['ticket_no'] ?? ''));
    }
    return $tb <=> $ta;
}

// ------------------------------------------------------------
// 新增送（销）货单：开票后信息即锁定，不可再修改
// ------------------------------------------------------------
if (isset($_POST['add'])) {
    // 文件锁：防止并发提交导致单号重复
    $fp = @fopen($jsonFile, 'r+');
    if (!$fp) { echo '数据文件无法打开，请检查目录写权限'; exit; }
    flock($fp, LOCK_EX);
    // 锁内重读，确保拿到最新列表
    rewind($fp);
    $list = json_decode(stream_get_contents($fp), true);
    if (!$list) $list = [];

    // 唯一 id：在锁内基于现有记录取最大值 +1，避免同一秒开多单时 id 冲突
    $newId = 0;
    foreach ($list as $t) {
        $newId = max($newId, (int)($t['id'] ?? 0));
    }
    $newId = max($newId + 1, 1);
    $ticketNo = genTicketNo($list);
    $buyer    = trim($_POST['buyer'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    if ($buyer === '') {
        header('Location: ticket.php?err=1');
        exit;
    }
    // 制票人 / 送货人：每张单据可随意填写，留空则取默认值 xxx
    $maker     = trim($_POST['maker'] ?? '');
    $deliverer = trim($_POST['deliverer'] ?? '');
    if ($maker === '')     $maker     = $CONFIG['maker'];
    if ($deliverer === '') $deliverer = $CONFIG['deliverer'];
    // 开票时间：固定取服务器北京时间，前端不可修改
    $invoice_time = date('Y-m-d H:i:s');

    $items = [];
    $total = 0;
    $nameArr  = $_POST['item_name'] ?? [];
    $unitArr  = $_POST['item_unit'] ?? [];
    $qtyArr   = $_POST['item_qty'] ?? [];
    $priceArr = $_POST['item_price'] ?? [];
    $noteArr  = $_POST['item_note'] ?? [];
    $cnt = count($nameArr);
    for ($i = 0; $i < $cnt; $i++) {
        $name = trim($nameArr[$i]);
        if ($name === '') continue; // 跳过空行
        $qty   = floatval($qtyArr[$i] ?? 0);
        $price = floatval($priceArr[$i] ?? 0);
        $amount = round($qty * $price, 2);
        $total += $amount;
        $items[] = [
            'name'   => $name,
            'unit'   => trim($unitArr[$i] ?? ''),
            'qty'    => $qty,
            'price'  => $price,
            'amount' => $amount,
            'note'   => trim($noteArr[$i] ?? ''),
        ];
    }
    $new = [
        'id'              => $newId,   // 唯一 id：锁内取现有最大 id + 1（用 time() 会在同一秒重复，导致删一张连带删另一张）
        'ticket_no'       => $ticketNo,
        'create_time'     => date('Y-m-d H:i:s'),
        'invoice_time'    => $invoice_time,
        'date'            => date('Y年m月d日'),
        'invoice_date_cn' => date('Y年m月d日', strtotime($invoice_time)),
        'buyer'           => $buyer,
        'address'         => $address,
        'maker'           => $maker,
        'deliverer'       => $deliverer,
        'items'           => $items,
        'total'           => round($total, 2),
        'total_cn'        => rmbUpper($total),
        'brand'           => $CONFIG['brand'], // 开票时锁定品牌（商户名称），之后改名不影响此单
    ];
    $list[] = $new;
    // 回写数据文件
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);

    // 同时生成一张独立 HTML 票据文件（按日期分文件夹保存到「票据留存」）
    $dateDir = $ticketRoot . '/' . date('Ymd');
    if (!file_exists($dateDir)) { mkdir($dateDir, 0755, true); }
    $html = renderTicketHtml($new, $CONFIG);
    file_put_contents($dateDir . '/' . $ticketNo . '.html', $html);
    header('Location: ticket.php?view=' . $ticketNo . '&new=1');
    exit;
}

// ------------------------------------------------------------
// 导入历史票据（上传 .html 票据文件 / 扫描「票据留存」目录）
// 已存在单号自动跳过，绝不重复导入；导入后同样锁定不可修改
// ------------------------------------------------------------
if (isset($_POST['import_scan']) || isset($_POST['import_tickets'])) {
    $report = ['ok' => 0, 'skip' => 0, 'err' => []];
    $fp = @fopen($jsonFile, 'r+');
    if (!$fp) { echo '数据文件无法打开，请检查目录写权限'; exit; }
    flock($fp, LOCK_EX);
    rewind($fp);
    $list = json_decode(stream_get_contents($fp), true);
    if (!$list) $list = [];

    $existingNos = [];
    $maxId = 0;
    foreach ($list as $t) {
        $existingNos[$t['ticket_no'] ?? ''] = true;
        $maxId = max($maxId, (int)($t['id'] ?? 0));
    }
    $newId = max($maxId, time()); // 自增唯一 id，避免溢出/重复

    // 收集待导入的票据文件
    $toImport = [];
    if (isset($_POST['import_scan'])) {
        // 方式一：扫描「票据留存」目录下所有子目录的 .html 文件
        if (is_dir($ticketRoot)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ticketRoot, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && strtolower($f->getExtension()) === 'html') {
                    $toImport[] = ['html' => file_get_contents($f->getPathname()), 'name' => $f->getBasename(), 'path' => $f->getPathname()];
                }
            }
        }
    } else {
        // 方式二：上传已开好的票据 .html 文件（可多选）
        if (!empty($_FILES['ticket_files']) && is_array($_FILES['ticket_files']['name'])) {
            $n = count($_FILES['ticket_files']['name']);
            for ($i = 0; $i < $n; $i++) {
                $fname = (string)$_FILES['ticket_files']['name'][$i];
                if (($_FILES['ticket_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $report['err'][] = $fname . '：上传失败'; continue;
                }
                if (strtolower(pathinfo($fname, PATHINFO_EXTENSION)) !== 'html') {
                    $report['err'][] = $fname . '：不是 .html 票据文件'; continue;
                }
                $toImport[] = ['html' => file_get_contents($_FILES['ticket_files']['tmp_name'][$i]), 'name' => $fname, 'tmp' => $_FILES['ticket_files']['tmp_name'][$i]];
            }
        }
    }

    foreach ($toImport as $src) {
        $ft = date('Y-m-d H:i:s', (int)@filemtime(isset($src['path']) ? $src['path'] : $src['tmp']));
        if ($ft === '1970-01-01 08:00:00' || $ft === '1970-01-01 00:00:00') $ft = date('Y-m-d H:i:s');
        $rec = parseTicketHtml($src['html'], $ft);
        if ($rec === null) { $report['err'][] = $src['name'] . '：无法识别送（销）货单号'; continue; }
        if (isset($existingNos[$rec['ticket_no']])) { $report['skip']++; continue; }
        // 上传的票据归档到「票据留存 / 开票日期 /」目录
        if (isset($src['tmp']) && !isset($src['path'])) {
            $dateDir = $ticketRoot . '/' . str_replace('-', '', substr((string)$rec['invoice_time'], 0, 10));
            if (!file_exists($dateDir)) { @mkdir($dateDir, 0755, true); }
            $dest = $dateDir . '/' . $rec['ticket_no'] . '.html';
            if (!file_exists($dest)) { @move_uploaded_file($src['tmp'], $dest); }
        }
        $rec['id'] = ++$newId;
        $list[] = $rec;
        $existingNos[$rec['ticket_no']] = true;
        $report['ok']++;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);

    @file_put_contents(__DIR__ . '/import_report.json', json_encode($report, JSON_UNESCAPED_UNICODE));
    header('Location: ticket.php?imported=1#history');
    exit;
}

// ------------------------------------------------------------
// 添加常用制票人 / 送货人（长期保存，开票时可下拉选择）
// ------------------------------------------------------------
if (isset($_POST['save_people'])) {
    $pType = $_POST['people_type'] ?? '';
    $pName = trim($_POST['people_name'] ?? '');
    if (!in_array($pType, ['maker', 'deliverer'], true) || $pName === '') {
        header('Location: ticket.php');
        exit;
    }
    $fp = @fopen($peopleFile, 'r+');
    if (!$fp) { echo '数据文件无法打开，请检查目录写权限'; exit; }
    flock($fp, LOCK_EX);
    rewind($fp);
    $people = json_decode(stream_get_contents($fp), true);
    if (!is_array($people)) $people = ['makers' => [], 'deliverers' => []];
    if (!isset($people['makers']) || !is_array($people['makers']))     $people['makers'] = [];
    if (!isset($people['deliverers']) || !is_array($people['deliverers'])) $people['deliverers'] = [];
    $key = $pType === 'maker' ? 'makers' : 'deliverers';
    if (!in_array($pName, $people[$key], true)) {
        $people[$key][] = $pName;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($people, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
    header('Location: ticket.php?saved=1&saved_type=' . urlencode($pType) . '&saved_name=' . urlencode($pName) . '#addform');
    exit;
}

// ------------------------------------------------------------
// 删除常用制票人 / 送货人（人员离职后清理，仅从「常用」下拉移除）
// 注意：只影响 people.json，已开票据上记录的姓名保持不变，符合“已开票锁定”要求
// ------------------------------------------------------------
if (isset($_POST['del_people'])) {
    $pType = $_POST['people_type'] ?? '';
    $pName = trim($_POST['people_name'] ?? '');
    if (!in_array($pType, ['maker', 'deliverer'], true) || $pName === '') {
        header('Location: ticket.php');
        exit;
    }
    $key = $pType === 'maker' ? 'makers' : 'deliverers';
    $fp = @fopen($peopleFile, 'r+');
    if (!$fp) { echo '数据文件无法打开，请检查目录写权限'; exit; }
    flock($fp, LOCK_EX);
    rewind($fp);
    $pdata = json_decode(stream_get_contents($fp), true);
    if (!is_array($pdata)) $pdata = ['makers' => [], 'deliverers' => []];
    if (!isset($pdata['makers']) || !is_array($pdata['makers']))           $pdata['makers'] = [];
    if (!isset($pdata['deliverers']) || !is_array($pdata['deliverers']))   $pdata['deliverers'] = [];
    $pdata[$key] = array_values(array_filter($pdata[$key], function ($x) use ($pName) {
        return (string)$x !== $pName;
    }));
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($pdata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
    header('Location: ticket.php?pdel=1#addform');
    exit;
}

// ------------------------------------------------------------
// 保存品牌设置（送货系统名称 / Logo 图像）
// ------------------------------------------------------------
if (isset($_POST['save_settings'])) {
    // 1) 更新送货系统名称
    $newBrand = trim($_POST['settings_brand'] ?? '');
    if ($newBrand !== '') {
        $fp = @fopen($settingsFile, 'r+');
        if ($fp) {
            flock($fp, LOCK_EX);
            rewind($fp);
            $s = json_decode(stream_get_contents($fp), true);
            if (!is_array($s)) $s = [];
            $s['brand'] = $newBrand;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    // 2) 更新 Logo 图像（替换 log.png；仅允许常见图片格式，限 2MB）
    //    先删旧图再写入：Windows 下 log.png 被设为只读 / 被资源管理器预览占用时，
    //    直接覆盖会静默失败，表现为「点了保存但 Logo 和标签图标没变」。
    //    写入成功后 touch 刷新时间戳，页面引用的 log.png?v=时间戳 随之变化，
    //    浏览器标签图标（favicon）才会强制换成新图。
    $logoState = 'none';
    if (isset($_FILES['logo_file']) && ($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $size = $_FILES['logo_file']['size'] ?? 0;
        $mime = @getimagesize($_FILES['logo_file']['tmp_name']);
        $allowed = ['image/png', 'image/jpeg', 'image/gif'];
        if ($size > 0 && $size <= 2 * 1024 * 1024 && $mime && in_array($mime['mime'], $allowed, true)) {
            $logoDest = __DIR__ . '/log.png';
            $oldVer   = file_exists($logoDest) ? (int)filemtime($logoDest) : 0;
            if (file_exists($logoDest)) { @unlink($logoDest); }
            if (@move_uploaded_file($_FILES['logo_file']['tmp_name'], $logoDest)) {
                // 修改时间必须比旧图新，否则版本号不变，浏览器标签图标仍显示旧图
                if ((int)@filemtime($logoDest) <= $oldVer) { @touch($logoDest, $oldVer + 1); }
                clearstatcache(true, $logoDest);
                $logoState = 'ok';
            } else {
                $logoState = 'fail';
            }
        } else {
            $logoState = 'invalid';
        }
    }
    header('Location: ticket.php?brand=1'
        . ($logoState === 'ok'      ? '&logook=1'   : '')
        . ($logoState === 'fail'    ? '&logofail=1' : '')
        . ($logoState === 'invalid' ? '&logobad=1'  : '')
        . '&t=' . time());
    exit;
}

// ------------------------------------------------------------
// 删除送（销）货单（同时移除对应的票据 HTML 文件）
// ------------------------------------------------------------
if (isset($_GET['del'])) {
    $fp = @fopen($jsonFile, 'r+');
    if (!$fp) { echo '数据文件无法打开，请检查目录写权限'; exit; }
    flock($fp, LOCK_EX);
    rewind($fp);
    $list = json_decode(stream_get_contents($fp), true);
    if (!$list) $list = [];
    $id = $_GET['del'];
    $delNo = '';
    foreach ($list as $k => $v) {
        if ((string)($v['id'] ?? '') === (string)$id) {
            $delNo = $v['ticket_no'];
            unset($list[$k]);
        }
    }
    $list = array_values($list);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($delNo) {
        // 从「票据留存」目录中查找对应日期的 HTML 文件并删除
        $dayDirs = glob($ticketRoot . '/*', GLOB_ONLYDIR);
        foreach ($dayDirs as $dd) {
            $f = $dd . '/' . $delNo . '.html';
            if (file_exists($f)) { unlink($f); break; }
        }
    }
    header('Location: ticket.php');
    exit;
}

// ------------------------------------------------------------
// 批量删除历史单据：勾选多张一次性删除（票据文件同步移除，不可恢复）
// ------------------------------------------------------------
if (isset($_POST['del_batch'])) {
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) { $ids = [$ids]; }
    $ids = array_values(array_filter(array_map('strval', $ids), function ($x) { return $x !== ''; }));
    if (!$ids) { header('Location: ticket.php?delnum=0#history'); exit; }

    $fp = @fopen($jsonFile, 'r+');
    if (!$fp) { echo '数据文件无法打开，请检查目录写权限'; exit; }
    flock($fp, LOCK_EX);
    rewind($fp);
    $list = json_decode(stream_get_contents($fp), true);
    if (!$list) $list = [];

    // 严格字符串比较 id，避免松散比较误删
    $idMap = array_flip($ids);
    $delNos = [];
    $keep = [];
    foreach ($list as $v) {
        if (isset($idMap[(string)($v['id'] ?? '')])) {
            $delNos[] = (string)($v['ticket_no'] ?? '');
        } else {
            $keep[] = $v;
        }
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($delNos) {
        $dayDirs = glob($ticketRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($delNos as $no) {
            if ($no === '') continue;
            foreach ($dayDirs as $dd) {
                $f = $dd . '/' . $no . '.html';
                if (file_exists($f)) { @unlink($f); break; }
            }
        }
    }
    header('Location: ticket.php?delnum=' . count($delNos) . '#history');
    exit;
}

// ------------------------------------------------------------
// 导出 CSV（含 BOM，Excel 打开不乱码）
// ------------------------------------------------------------
if (isset($_GET['export'])) {
    // 按「开票时间」筛选日期区间（留空表示不限）；格式不合法直接忽略，避免注入
    $from = trim((string)($_GET['from'] ?? ''));
    $to   = trim((string)($_GET['to'] ?? ''));
    if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = ''; }
    if ($to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = ''; }
    if ($from !== '' && $to !== '' && $from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

    $exportList = [];
    foreach ($list as $v) {
        $day = ticketInvDay($v);              // 与统计口径一致：按票面开票时间
        if ($from !== '' && $day < $from) { continue; }
        if ($to   !== '' && $day > $to)   { continue; }
        $exportList[] = $v;
    }
    usort($exportList, 'cmpByInvoiceTimeDesc');

    // 文件名带上区间，方便区分（纯 ASCII，避免各种浏览器下载乱码）
    if ($from !== '' && $to !== '') {
        $suffix = '_' . str_replace('-', '', $from) . '-' . str_replace('-', '', $to);
    } elseif ($from !== '') {
        $suffix = '_from' . str_replace('-', '', $from);
    } elseif ($to !== '') {
        $suffix = '_to' . str_replace('-', '', $to);
    } else {
        $suffix = '';
    }
    header("Content-Disposition:attachment;filename=delivery" . $suffix . "_" . date('Ymd') . ".csv");
    header("Content-type:text/csv;charset=utf-8");
    echo "\xEF\xBB\xBF";
    echo "送（销）货单号,系统存单时间,开票时间,收货单位,地址,合计,大写\r\n";
    foreach ($exportList as $v) {
        $inv = ticketInvShow($v);
        $row = [
            $v['ticket_no'] ?? '', $v['create_time'] ?? '', $inv,
            $v['buyer'] ?? '', $v['address'] ?? '', $v['total'] ?? 0, $v['total_cn'] ?? '',
        ];
        $esc = array_map(function ($x) {
            $x = (string)$x;
            // 防 CSV 公式注入：以 = + - @ 开头的值前面加单引号，避免 Excel 当公式执行
            if ($x !== '' && strpos('=+-@', $x[0]) !== false) { $x = "'" . $x; }
            return '"' . str_replace('"', '""', $x) . '"';
        }, $row);
        echo implode(',', $esc) . "\r\n";
    }
    exit;
}

/**
 * 渲染单张送（销）货单的 HTML（A4 打印版）
 * @param array $t   单据数据
 * @param array $cfg 全局配置
 */
function renderTicketHtml($t, $cfg)
{
    $itemsHtml = '';
    $maxRows = max(count($t['items']), 5);
    for ($i = 0; $i < $maxRows; $i++) {
        $it = $t['items'][$i] ?? null;
        $name  = is_array($it) && isset($it['name'])   ? $it['name']   : '';
        $unit  = is_array($it) && isset($it['unit'])    ? $it['unit']   : '';
        $qty   = is_array($it) && isset($it['qty']) && $it['qty'] !== '' && $it['qty'] !== null ? $it['qty'] : '';
        $price = is_array($it) && isset($it['price']) && $it['price'] !== '' && $it['price'] !== null ? number_format($it['price'], 2) : '';
        $amt   = is_array($it) && isset($it['amount']) && $it['amount'] !== '' ? number_format($it['amount'], 2) : '';
        $note  = is_array($it) && isset($it['note'])    ? $it['note']    : '';
        $itemsHtml .= '<tr>';
        $itemsHtml .= '<td>' . htmlspecialchars($name) . '</td>';
        $itemsHtml .= '<td>' . htmlspecialchars($unit) . '</td>';
        $itemsHtml .= '<td>' . htmlspecialchars((string)$qty) . '</td>';
        $itemsHtml .= '<td>' . $price . '</td>';
        $itemsHtml .= '<td>' . $amt . '</td>';
        $itemsHtml .= '<td>' . htmlspecialchars($note) . '</td>';
        $itemsHtml .= '</tr>';
    }
    $total = number_format($t['total'], 2);
    $totalCnEsc = htmlspecialchars((string)($t['total_cn'] ?? ''));
    $invTimeShow = $t['invoice_time'] ?? $t['create_time'];
    $noEsc = htmlspecialchars($t['ticket_no']);
    $buyerEsc = htmlspecialchars($t['buyer']);
    $addressEsc = htmlspecialchars($t['address']);
    $brand = htmlspecialchars($cfg['brand']);
    // 制票人 / 送货人：优先取单据上填写的值，缺失时回退到配置默认值
    $maker = (isset($t['maker']) && trim($t['maker']) !== '')
        ? htmlspecialchars($t['maker']) : htmlspecialchars($cfg['maker']);
    $delivererRaw = (isset($t['deliverer']) && trim($t['deliverer']) !== '')
        ? $t['deliverer'] : $cfg['deliverer'];
    // 送货单位：跟随「送货系统名称」（品牌）——在页面「编辑」里改品牌后，新开票据的送货单位同步变化
    $unitLabel = trim((string)($cfg['brand'] ?? '')) !== '' ? (string)$cfg['brand'] : (string)($cfg['unit'] ?? '');
    $unitName = htmlspecialchars($unitLabel);
    $deliverer = trim($delivererRaw) !== ''
        ? '<span>送货人：' . htmlspecialchars($delivererRaw) . '</span>' : '';
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>{$brand}送（销）货单 {$noEsc}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:"SimSun","宋体",serif; background:#f0f0f0; padding:20px; }
  .page { width:210mm; min-height:297mm; margin:0 auto; background:#fff; padding:18mm 16mm; }
  h1 { text-align:center; font-size:26px; letter-spacing:6px; font-weight:bold; margin-bottom:18px; text-decoration:underline; text-underline-offset:6px; }
  .meta { font-size:14px; line-height:1.6; margin-bottom:2px; overflow:hidden; }
  .meta .left { float:left; }
  .meta .right { float:right; }
  table { width:100%; border-collapse:collapse; margin-top:6px; }
  th,td { border:1px solid #000; text-align:center; font-size:14px; }
  th { background:#fff; font-weight:bold; padding:6px 4px; }
  td { height:30px; }
  .col-name{width:28%;} .col-unit{width:8%;}
  .col-qty{width:10%;} .col-price{width:12%;} .col-amt{width:16%;} .col-note{width:16%;}
  .total-row td { height:34px; font-size:13px; }
  .total-label { font-weight:bold; }
  .num-unit { letter-spacing:2px; }
  .rmb { text-align:left !important; padding-left:8px; }
  .footer { margin-top:14px; font-size:14px; display:flex; justify-content:space-between; }
  .toolbar { max-width:210mm; margin:0 auto 12px; text-align:right; }
  .toolbar button { padding:8px 20px; background:#2277ee; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:14px; margin-left:8px; }
  @media print {
    body { background:#fff; padding:0; }
    .toolbar { display:none; }
    .page { width:auto; min-height:auto; margin:0; padding:15mm 14mm; }
    @page { size:A4; margin:0; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button onclick="confirmPrint()">打 印</button>
  <button onclick="location.href='ticket.php'" style="background:#666;">返 回</button>
</div>
<script>
function confirmPrint(){
  if(confirm('确认打印这张送（销）货单？')){
    window.print();
  }
}
</script>
<div class="page">
  <h1>{$brand}送（销）货单</h1>
  <div class="meta">
    <span class="left">送（销）货单号：{$noEsc}</span>
    <span class="right">开票时间：{$invTimeShow}</span>
  </div>
  <div class="meta">
    <span class="left">收货单位：{$buyerEsc}</span>
  </div>
  <div class="meta">
    <span class="left">地址、电话：{$addressEsc}</span>
  </div>
  <table>
    <thead>
      <tr>
        <th class="col-name">名称及规格</th>
        <th class="col-unit">单位</th>
        <th class="col-qty">数量</th>
        <th class="col-price">单价</th>
        <th class="col-amt">金额</th>
        <th class="col-note">备注</th>
      </tr>
    </thead>
    <tbody>
{$itemsHtml}
    </tbody>
    <tfoot>
      <tr class="total-row">
        <td class="total-label">合计</td>
        <td colspan="4" class="num-unit">{$totalCnEsc}</td>
        <td class="rmb">￥{$total}</td>
      </tr>
    </tfoot>
  </table>
  <div class="footer">
    <span>制票：{$maker}</span>
    {$deliverer}
    <span>送货单位：{$unitName}</span>
    <span></span>
  </div>
</div>
</body>
</html>
HTML;
}

// ------------------------------------------------------------
// 票据打印视图
// ------------------------------------------------------------
if (isset($_GET['view'])) {
    $viewNo = $_GET['view'];
    $found = null;
    foreach ($list as $t) {
        if ($t['ticket_no'] == $viewNo) { $found = $t; break; }
    }
    if (!$found) { echo '送（销）货单不存在'; exit; }
    // 已开票信息锁定：优先展示开票时生成的独立 HTML 快照（品牌/商户名称已固定），
    // 之后修改系统名称或 Logo 不会影响历史票据。
    // 快照按单号在所有日期子目录中查找，不依赖 create_time 推算，避免导入数据时间偏差导致找不到。
    $snapFile = '';
    $dayDirs = glob($ticketRoot . '/*', GLOB_ONLYDIR);
    if ($dayDirs) {
        foreach ($dayDirs as $dd) {
            $f = $dd . '/' . $found['ticket_no'] . '.html';
            if (file_exists($f)) { $snapFile = $f; break; }
        }
    }
    if ($snapFile !== '') {
        header('Content-Type: text/html; charset=utf-8');
        readfile($snapFile);
        // 新开单后的查看页：通知开票列表页就地更新（无需手动刷新）
        if (isset($_GET['new'])) {
            echo "\n<script>(function(){try{if(window.opener&&!window.opener.closed){window.opener.postMessage({type:'ticket-saved'},'*');}}catch(e){}try{localStorage.setItem('ticket_saved_at',String(Date.now()));}catch(e){}})();</script>";
        }
        exit;
    }
    // 快照缺失时按单据上锁定的品牌渲染（不使用当前系统名称，保证商户名更改后历史票不变）
    $cfg = $CONFIG;
    if (!empty($found['brand']) && trim((string)$found['brand']) !== '') {
        $cfg['brand'] = $found['brand'];
    }
    echo renderTicketHtml($found, $cfg);
    if (isset($_GET['new'])) {
        echo "\n<script>(function(){try{if(window.opener&&!window.opener.closed){window.opener.postMessage({type:'ticket-saved'},'*');}}catch(e){}try{localStorage.setItem('ticket_saved_at',String(Date.now()));}catch(e){}})();</script>";
    }
    exit;
}

// ------------------------------------------------------------
// 打开票据文件所在位置（仅 Windows 本地版，资源管理器定位该文件）
// ------------------------------------------------------------
if (isset($_GET['open'])) {
    $openNo = $_GET['open'];
    $ok = false;
    foreach ($list as $t) {
        if ($t['ticket_no'] === $openNo) { $ok = true; break; }
    }
    if (!$ok) { echo '送（销）货单不存在'; exit; }
    $file = '';
    $dayDirs = glob($ticketRoot . '/*', GLOB_ONLYDIR);
    foreach ($dayDirs as $dd) {
        $f = $dd . '/' . $openNo . '.html';
        if (file_exists($f)) { $file = realpath($f); break; }
    }
    if ($file === '' || $file === false) { echo '票据文件不存在（可能已被删除）'; exit; }
    $file = str_replace('"', '', $file);
    if (function_exists('exec')) {
        exec('explorer.exe /select,"' . $file . '"', $out, $code);
        // explorer 的 /select 定位成功时返回码为 1，属正常现象，无需处理
    } else {
        echo '当前 PHP 环境禁用了 exec，无法打开资源管理器';
        exit;
    }
    header('Location: ticket.php#history');
    exit;
}

// 复制重开用：把一张历史单据的“可编辑字段”抽出来，点「复制重开」时填回新增表单
// （单号/时间/合计不复制，由系统重新生成；货物名称、单位、数量、单价、备注全部可再改）
$ticketFillData = [];
foreach ($displayList as $it) {
    $rows = [];
    foreach ((array)($it['items'] ?? []) as $x) {
        if (!is_array($x) || trim((string)($x['name'] ?? '')) === '') continue;
        $rows[] = [
            'name'  => (string)$x['name'],
            'unit'  => (string)($x['unit'] ?? ''),
            'qty'   => (string)($x['qty'] ?? ''),
            'price' => (string)($x['price'] ?? ''),
            'note'  => (string)($x['note'] ?? ''),
        ];
    }
    $ticketFillData[(string)($it['ticket_no'] ?? '')] = [
        'buyer'     => (string)($it['buyer'] ?? ''),
        'address'   => (string)($it['address'] ?? ''),
        'maker'     => (string)($it['maker'] ?? ''),
        'deliverer' => (string)($it['deliverer'] ?? ''),
        'items'     => $rows,
    ];
}

// ------------------------------------------------------------
// 统计信息（顶部展示用）
// 口径说明：今日/累计金额一律按「开票时间」归属，与系统存单时间、
// 导入时间无关——导入旧票据不会把金额算进今天。
// ------------------------------------------------------------
$todayStr = date('Y-m-d');
$todayCount = 0;
$todayTotal = 0;
$totalAll = 0;
foreach ($list as $t) {
    $amt = (float)($t['total'] ?? 0);
    $totalAll += $amt;
    if (ticketInvDay($t) === $todayStr) {
        $todayCount++;
        $todayTotal += $amt;
    }
}

/**
 * 渲染历史列表中的一行（页面首屏与“保存后局部刷新”共用同一份标记，避免两处不一致）
 */
function renderHistoryRow(array $item)
{
    $no    = (string)($item['ticket_no'] ?? '');
    $noE   = htmlspecialchars($no);
    $idE   = htmlspecialchars((string)($item['id'] ?? ''));
    $inv   = htmlspecialchars(ticketInvShow($item));
    $ct    = htmlspecialchars((string)($item['create_time'] ?? ''));
    $buyer = htmlspecialchars((string)($item['buyer'] ?? ''));
    $amt   = number_format((float)($item['total'] ?? 0), 2);
    $cn    = htmlspecialchars((string)($item['total_cn'] ?? ''));

    return '<tr>'
        . '<td class="sel"><input type="checkbox" class="rowchk" name="ids[]" value="' . $idE . '" form="batchDelForm" title="勾选后可批量删除"></td>'
        . '<td class="ticket-no">' . $noE . '</td>'
        . '<td>' . $ct . '</td>'
        . '<td>' . $inv . '</td>'
        . '<td>' . $buyer . '</td>'
        . '<td class="amount">' . $amt . '</td>'
        . '<td class="upper">' . $cn . '</td>'
        . '<td><div class="ops">'
        . '<a href="?view=' . $noE . '" target="_blank" class="btn blue sm">查看/打印</a>'
        . '<button type="button" class="btn green sm" data-no="' . $noE . '" onclick="fillFromTicket(this.dataset.no)"'
        . ' title="把这张单的收货单位、货物名称、数量单价等填入上方新单，可随意修改后重新开票">复制重开</button>'
        . '<a href="?open=' . $noE . '" title="在资源管理器中定位该票据文件" class="btn orange sm" onclick="saveScroll()">打开位置</a>'
        . '<a href="?del=' . $idE . '" onclick="return confirm(\'确定删除该送（销）货单？其票据文件也会一并移除，删除后不可恢复\')" class="btn red sm">删除</a>'
        . '</div></td>'
        . '</tr>';
}

// ------------------------------------------------------------
// 局部刷新接口：保存新单后由查看页通知，本页直接替换列表，无需整页刷新
// ------------------------------------------------------------
if (isset($_GET['partial'])) {
    header('Content-Type: application/json; charset=utf-8');
    $rows = '';
    foreach ($displayList as $item) {
        $rows .= renderHistoryRow($item);
    }
    echo json_encode([
        'rows'       => $rows,
        'count'      => count($displayList),
        'matched'    => $matchedCount,
        'total'      => $historyTotal,
        'kw'         => $searchKw,
        'todayCount' => $todayCount,
        'todayTotal' => number_format($todayTotal, 2),
        'totalAll'   => number_format($totalAll, 2),
        'fill'       => $ticketFillData,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- 浏览器标签图标：跟随 log.png；?v=修改时间，换图后标签图标立即更新 -->
<link rel="icon" type="<?= $logoMime ?>" href="<?= $logoUrl ?>">
<link rel="shortcut icon" href="<?= $logoUrl ?>">
<link rel="apple-touch-icon" href="<?= $logoUrl ?>">
<title><?= htmlspecialchars($CONFIG['brand']) ?>送（销）货单管理</title>
<style>
:root{
  --blue:#2563eb; --blue-d:#1d4ed8; --blue-l:#eff6ff;
  --green:#16a34a; --green-l:#f0fdf4;
  --orange:#f59e0b; --orange-l:#fff7ed;
  --red:#dc2626; --red-l:#fef2f2;
  --ink:#1e293b; --ink-2:#475569; --ink-3:#94a3b8;
  --line:#e2e8f0;
  --radius:14px;
  --shadow:0 1px 2px rgba(15,23,42,.06),0 8px 24px rgba(15,23,42,.06);
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Microsoft YaHei","PingFang SC","Segoe UI",system-ui,sans-serif;background:linear-gradient(160deg,#eef2f7 0%,#e6ecf5 100%);color:var(--ink);min-height:100vh}
.app{max-width:1280px;margin:0 auto;padding:24px 20px 48px}
/* 顶栏 */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;background:#fff;border-radius:16px;box-shadow:var(--shadow);padding:18px 24px;margin-bottom:20px}
.brand{display:flex;align-items:center;gap:14px}
.brand-icon{width:72px;height:auto;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.15);display:flex;align-items:center;justify-content:center;flex:none;padding:4px}
.brand-icon img{display:block;width:100%;height:auto;border-radius:8px}
.brand h1{font-size:20px;font-weight:700;letter-spacing:.5px}
.brand-sub{font-size:12px;color:var(--ink-3);margin-top:3px}
.topdate{text-align:right;font-size:14px;color:var(--ink-2);line-height:1.6}
.topdate b{font-size:18px;color:var(--ink)}
.topdate span{display:block;font-size:12px;color:var(--ink-3)}
/* 统计 */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat{background:#fff;border-radius:14px;box-shadow:var(--shadow);padding:16px 18px;display:flex;align-items:center;gap:12px}
.stat-ico{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex:none}
.stat b{font-size:20px;line-height:1.2}
.stat p{font-size:12px;color:var(--ink-3);margin-top:2px}
.c-blue{background:var(--blue-l);color:var(--blue)}
.c-green{background:var(--green-l);color:var(--green)}
.c-orange{background:var(--orange-l);color:var(--orange)}
/* 卡片 */
.card{background:#fff;border-radius:16px;box-shadow:var(--shadow);padding:22px 24px;margin-bottom:20px}
.card-head{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.bar{width:4px;height:18px;border-radius:2px;background:var(--blue);flex:none}
.bar.orange{background:var(--orange)}
.bar.green{background:var(--green)}
.card-head h2{font-size:16px;font-weight:700}
.head-tag{margin-left:auto;font-size:12px;color:var(--ink-3);background:#f8fafc;border:1px solid var(--line);padding:3px 10px;border-radius:999px;white-space:nowrap}
.card-head.sub{margin:18px 0 12px}
/* 表单 */
.field{margin-bottom:12px}
.label{display:block;font-size:13px;font-weight:600;color:var(--ink-2);margin-bottom:6px}
.label i{color:var(--red);font-style:normal}
input[type=text],input[type=number],input[type=datetime-local],select,textarea{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:9px;font-size:14px;color:var(--ink);background:#fff;outline:none;transition:border-color .15s,box-shadow .15s}
input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.13)}
input[readonly]{background:#f1f5f9;color:var(--ink-2)}
.grid2{display:grid;grid-template-columns:2fr 3fr;gap:14px}
/* 常用人员选择行（制票人 / 送货人） */
.pick-row{display:flex;gap:8px;align-items:center}
.pick-row input{flex:1;min-width:0}
.pick-row select{flex:none;width:118px;background:#f8fafc}
.pick-row .btn{padding:9px 14px;flex:none}
/* 常用人员标签：每个名字带独立删除按钮，方便离职后清理 */
.people-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;min-height:24px;align-items:center}
.ptag{display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;border:1px solid var(--line);border-radius:999px;padding:2px 4px 2px 10px;font-size:12px;color:var(--ink-2);line-height:1.8}
.ptag-x{width:17px;height:17px;display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:50%;background:#e2e8f0;color:#64748b;font-size:13px;line-height:1;cursor:pointer;padding:0;font-family:inherit}
.ptag-x:hover{background:var(--red);color:#fff}
.tag-empty{color:var(--ink-3);font-size:12px;font-style:normal}
/* 复制成功轻提示 */
#copyToast{position:fixed;left:50%;bottom:36px;transform:translateX(-50%) translateY(20px);background:rgba(30,41,59,.94);color:#fff;font-size:13px;padding:10px 20px;border-radius:999px;box-shadow:0 8px 24px rgba(15,23,42,.28);opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:99}
#copyToast.show{opacity:1;transform:translateX(-50%) translateY(0)}
/* 复制重开后高亮新增单据卡片，提示内容已带入 */
@keyframes cardFlash{0%{box-shadow:0 0 0 0 rgba(22,163,74,.45)}100%{box-shadow:0 0 0 14px rgba(22,163,74,0)}}
.card.flash{animation:cardFlash .9s ease-out 2}
.alert.ok{background:var(--green-l);border-color:#bbf7d0;color:var(--green)}
/* 商品明细行 */
.row-item{display:grid;grid-template-columns:minmax(180px,3fr) 76px 104px 104px 112px 1.1fr 36px;gap:8px;align-items:center;background:#f8fafc;border:1px solid #eef1f5;border-radius:10px;padding:9px 10px;margin-bottom:8px}
.row-item input{margin:0}
.w-amt{background:var(--blue-l)!important;color:var(--blue)!important;font-weight:700}
.w-del{width:36px;height:32px;padding:0;border:1px solid var(--line);background:#fff;color:var(--red);font-size:18px;border-radius:8px;cursor:pointer;transition:background .15s}
.w-del:hover{background:var(--red-l)}
/* 按钮 */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 18px;border:none;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;transition:filter .15s,transform .05s;text-decoration:none;font-family:inherit;line-height:1.4}
.btn:active{transform:translateY(1px)}
.btn.primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.35)}
.btn.primary:hover{filter:brightness(1.08)}
.btn.green{background:var(--green);color:#fff}
.btn.green:hover{filter:brightness(1.08)}
.btn.gray{background:#f1f5f9;color:var(--ink-2);border:1px solid var(--line)}
.btn.gray:hover{background:#e2e8f0}
.btn.sm{padding:5px 10px;font-size:12px;border-radius:7px;font-weight:500}
.btn.blue{background:var(--blue);color:#fff}
.btn.blue:hover{filter:brightness(1.1)}
.btn.orange{background:#fff;color:#d97706;border:1px solid #fcd34d}
.btn.orange:hover{background:var(--orange-l)}
.btn.red{background:#fff;color:var(--red);border:1px solid #fecaca}
.btn.red:hover{background:var(--red-l)}
.add-row-btn{width:100%;border:1.5px dashed #cbd5e1;background:#fafbfc;color:var(--ink-2);padding:10px;border-radius:10px}
.add-row-btn:hover{background:#f1f5f9;color:var(--blue);border-color:var(--blue)}
/* 合计条 */
.total-bar{display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding:14px 18px;border-radius:12px;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff}
.total-bar .t-label{font-size:14px;opacity:.9}
.total-bar b{font-size:26px;letter-spacing:1px}
.form-actions{display:flex;gap:12px;margin-top:16px;flex-wrap:wrap}
/* 提示 */
.alert{display:flex;align-items:center;gap:10px;background:var(--red-l);border:1px solid #fecaca;color:var(--red);border-radius:12px;padding:12px 16px;margin-bottom:20px;font-size:14px}
/* 搜索 */
.searchbar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
.search-input{position:relative;flex:1;min-width:260px}
.search-input svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:16px;height:16px;stroke:#94a3b8;fill:none;stroke-width:2;stroke-linecap:round}
.search-input input{padding-left:36px}
/* 表格 */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:14px}
thead th{background:#f8fafc;color:var(--ink-2);font-weight:600;text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);white-space:nowrap}
tbody td{padding:11px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tbody tr:hover{background:var(--blue-l)}
.ticket-no{font-family:Consolas,"Courier New",monospace;color:var(--blue);font-weight:600}
.amount{color:var(--ink);font-weight:700}
.amount::before{content:"￥";color:var(--ink-3);font-weight:400}
.upper{color:var(--ink-2)}
.ops{display:flex;gap:6px;flex-wrap:wrap}
/* 批量删除：行首复选框列 */
th.sel,td.sel{width:38px;padding-left:10px;padding-right:0;text-align:center;vertical-align:middle}
.rowchk,#chkAll{width:17px;height:17px;cursor:pointer;accent-color:var(--blue);vertical-align:middle;margin:0}
#delSelBtn:disabled{opacity:.45;cursor:not-allowed}
#delSelBtn:not(:disabled){background:var(--red);color:#fff;border:1px solid var(--red)}
#delSelBtn:not(:disabled):hover{filter:brightness(1.08)}
/* 导出 CSV 的日期区间 */
.export-form{display:flex;align-items:center;gap:6px;margin-left:8px}
.export-form input[type=date]{width:132px;padding:5px 8px;border:1px solid var(--line);border-radius:8px;font-size:12px;color:var(--ink-2);background:#fff;outline:none;font-family:inherit}
.export-form input[type=date]:focus{border-color:var(--blue);box-shadow:0 0 0 2px rgba(37,99,235,.12)}
.export-form .dash{font-size:12px;color:var(--ink-3)}
.empty{border:1.5px dashed #cbd5e1;border-radius:12px;padding:36px;text-align:center;color:var(--ink-3);font-size:14px}
.foot-note{margin-top:12px;font-size:12px;color:var(--ink-3);line-height:1.7}
.foot-note b{color:var(--ink-2)}
/* ============ 响应式：平板 / 手机 ============ */
@media (max-width:960px){
  .stats{grid-template-columns:repeat(2,1fr)}
  .grid2{grid-template-columns:1fr}
  .row-item{grid-template-columns:1fr 1fr}
  .row-item .w-name{grid-column:span 2}
  .topbar{flex-direction:column;align-items:flex-start;gap:12px}
  .topdate{text-align:left;display:flex;align-items:baseline;gap:8px}
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  table{min-width:820px}
}
@media (max-width:640px){
  .app{padding:12px 10px 36px}
  .topbar{padding:14px;border-radius:14px}
  .brand-icon{width:46px;padding:3px}
  .brand h1{font-size:16px;line-height:1.35}
  .brand-sub{display:none}
  .topbar .btn{padding:7px 10px;font-size:12px}
  .stats{gap:9px;margin-bottom:14px}
  .stat{padding:11px 12px;gap:8px;border-radius:12px}
  .stat-ico{width:32px;height:32px;border-radius:8px}
  .stat b{font-size:16px}
  .stat p{font-size:11px}
  .card{padding:15px 13px;border-radius:14px;margin-bottom:14px}
  .card-head{gap:8px;margin-bottom:12px;flex-wrap:wrap}
  .card-head h2{font-size:15px;flex:1 1 100%}
  .card-head .bar{display:none}
  .card-head .btn{white-space:nowrap}
  .head-tag{display:none}
  input[type=text],input[type=number],input[type=datetime-local],select,textarea{font-size:16px;padding:10px 12px}
  .label{font-size:12px;margin-bottom:4px}
  .field{margin-bottom:10px}
  .grid2{gap:10px}
  .row-item{grid-template-columns:1fr 1fr;gap:6px;padding:9px;margin-bottom:8px}
  .row-item .w-name{grid-column:span 2}
  .row-item > :nth-child(6){grid-column:span 2}   /* 备注 */
  .w-del{width:100%;height:38px;grid-column:span 2}
  .pick-row{flex-wrap:wrap;gap:6px}
  .pick-row select{width:100%;flex:1 1 100%}
  .pick-row .btn{flex:1 1 auto;justify-content:center}
  .total-bar{padding:12px 14px;margin-top:10px}
  .total-bar .t-label{font-size:12px}
  .total-bar b{font-size:20px}
  .form-actions .btn{flex:1 1 100%}
  .btn{padding:11px 16px}
  .btn.sm{padding:7px 10px;font-size:12px}
  .searchbar{gap:8px}
  .search-input{min-width:0;flex:1 1 100%}
  .searchbar .btn{flex:1 1 auto;justify-content:center}
  thead th,tbody td{padding:9px 10px;font-size:13px}
  th.sel,td.sel{width:44px;padding-left:12px}
  .rowchk,#chkAll{width:20px;height:20px}
  .export-form{width:100%;margin-left:0;flex-wrap:wrap;gap:6px}
  .export-form input[type=date]{flex:1 1 40%;width:auto;font-size:14px;padding:8px}
  .export-form .btn{flex:1 1 100%}
  .ops{gap:5px}
  .ops .btn.sm{flex:1 1 45%;padding:8px 6px;text-align:center}
  .empty{padding:26px 14px;font-size:13px}
  .foot-note{font-size:11px}
  #copyToast{bottom:18px;max-width:88vw;text-align:center;font-size:12px;padding:9px 16px}
}
@media (max-width:380px){
  .stats{grid-template-columns:1fr}
  .brand h1{font-size:15px}
}
</style>
<style id="mobileFix">
/* === mobile-fix === */
@media (max-width:640px){
  /* ===== 商品明细行：改成「两列卡片」 =====
     修复点：body.no-code .row-item 的选择器优先级(0,2,0)高于
     原有的移动端 .row-item 规则(0,1,0)。关闭「显示商品编号」时
     （默认就是关闭），手机上仍是桌面 7 列布局，总列宽 584px
     塞进 344px 容器 → 数量/单价/金额被挤出屏幕外，根本没法填。 */
  .row-item,
  body.no-code .row-item{
    grid-template-columns:1fr 1fr!important;
    gap:8px!important;
    padding:12px!important;
    border-radius:12px!important;
  }
  .row-item>:nth-child(1){grid-column:1/-1!important}
  .row-item .w-code{grid-column:1/-1!important}          /* 商品编号 */
  .row-item .w-name{grid-column:1/-1!important}          /* 货物名称 */
  .row-item input[name="item_note[]"]{grid-column:1/-1!important}  /* 备注 */
  .row-item .w-del{grid-column:1/-1!important;width:100%!important;height:34px!important}
  .row-item input{padding:10px 12px}

  /* ===== 历史单据：表格转卡片，操作按钮不再被挤出屏幕 ===== */
  .table-wrap{overflow:visible!important}
  .table-wrap table{display:block!important;min-width:0!important;width:100%!important}
  .table-wrap thead{display:none!important}
  .table-wrap tbody{display:block!important}

  #hisBody tr{
    display:block!important;
    border:1px solid var(--line)!important;
    border-radius:12px!important;
    padding:12px 14px!important;
    margin-bottom:10px;
  }
  #hisBody tr:hover{background:transparent!important}

  #hisBody td{
    display:flex!important;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    border:0!important;
    padding:4px 0!important;
    text-align:right;
    font-size:13px;
    line-height:1.6;
    white-space:normal!important;
    word-break:break-word;
  }
  #hisBody td::before{
    flex:none;
    color:var(--ink-3);
    font-size:12px;
    font-weight:400;
    text-align:left;
    white-space:nowrap;
  }
  #hisBody td:nth-child(1){justify-content:flex-start;padding-bottom:6px!important}
  #hisBody td:nth-child(1)::before{content:"";display:none}
  #hisBody td:nth-child(2)::before{content:"单号"}
  #hisBody td:nth-child(3)::before{content:"存单时间"}
  #hisBody td:nth-child(4)::before{content:"开票时间"}
  #hisBody td:nth-child(5)::before{content:"收货单位"}
  #hisBody td:nth-child(6)::before{content:"合计金额"}
  #hisBody td:nth-child(7)::before{content:"大写"}
  #hisBody td:nth-child(8){display:block!important;padding-top:10px!important}
  #hisBody td:nth-child(8)::before{content:""!important;display:none!important}

  #hisBody .ops{display:grid!important;grid-template-columns:1fr 1fr;gap:8px!important}
  #hisBody .ops .btn{width:100%;justify-content:center;white-space:nowrap}
  /* 按钮个数为奇数时，最后一个占满整行，避免右下角留空 */
  #hisBody .ops .btn:last-child:nth-child(odd){grid-column:1/-1}

  .ticket-no{font-size:13px}
  .amount{font-size:14px}
}
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand">
      <div class="brand-icon">
        <img src="<?= $logoUrl ?>" alt="<?= htmlspecialchars($CONFIG['brand']) ?>">
      </div>
      <div>
        <div style="display:flex;align-items:center;gap:10px">
          <h1><?= htmlspecialchars($CONFIG['brand']) ?>送（销）货单 · 管理系统</h1>
          <button class="btn gray sm" onclick="toggleSettings()" title="修改送货系统名称 / Logo 图像">✎ 编辑</button>
        </div>
        <p class="brand-sub">送（销）货单管理 · 票据留存 · 历史单据一键定位</p>
      </div>
    </div>
    <div class="topdate">
      <b><?= date('Y年m月d日') ?></b>
      <span>北京时间</span>
    </div>
  </header>

  <!-- 品牌设置面板：编辑送货系统名称 / Logo（点「✎ 编辑」展开） -->
  <section class="card" id="settingsPanel" style="display:none">
    <div class="card-head">
      <span class="bar orange"></span>
      <h2>编辑送货系统名称 / Logo</h2>
      <span class="head-tag">保存后立即生效，票据与登录页同步更新</span>
    </div>
    <form method="post" enctype="multipart/form-data" action="ticket.php#settingsPanel">
      <input type="hidden" name="save_settings" value="1">
      <div class="grid2">
        <div class="field">
          <span class="label">送货系统名称（品牌）</span>
          <input type="text" name="settings_brand" value="<?= htmlspecialchars($CONFIG['brand']) ?>" placeholder="如：若琳AI">
        </div>
        <div class="field">
          <span class="label">Logo 图像（替换当前 logo，限 2MB：PNG / JPG / GIF）</span>
          <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn primary">保存设置</button>
        <button type="button" class="btn gray" onclick="toggleSettings()">关闭</button>
      </div>
    </form>
  </section>

  <script>
  function toggleSettings(){
    var p = document.getElementById('settingsPanel');
    if(p){ p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'block' : 'none'; }
  }
  </script>

  <section class="stats">
    <div class="stat">
      <div class="stat-ico c-blue"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg></div>
      <div><b id="statTotal0"><?= $historyTotal ?></b><p>单据总数</p></div>
    </div>
    <div class="stat">
      <div class="stat-ico c-green"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></div>
      <div><b id="statTodayCount"><?= $todayCount ?></b><p>今日新增</p></div>
    </div>
    <div class="stat">
      <div class="stat-ico c-orange"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9 8l3 4 3-4M12 12v5"/></svg></div>
      <div><b id="statTodayTotal">￥<?= number_format($todayTotal,2) ?></b><p>今日金额</p></div>
    </div>
    <div class="stat">
      <div class="stat-ico c-blue"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 13h.01"/></svg></div>
      <div><b id="statTotalAll">￥<?= number_format($totalAll,2) ?></b><p>累计金额</p></div>
    </div>
  </section>

  <?php if (isset($_GET['delnum'])): ?>
  <div class="alert ok" id="delnumAlert">
    <span><?= ((int)$_GET['delnum'] > 0) ? ('已删除 ' . (int)$_GET['delnum'] . ' 张送（销）货单，对应票据文件已一并移除。') : '未选择任何单据，未执行删除。' ?></span>
  </div>
  <?php endif; ?>
  <?php if (isset($_GET['err'])): ?>
  <div class="alert">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
    <span>收货单位不能为空，请填写后再保存。</span>
  </div>
  <?php endif; ?>
  <?php if (isset($_GET['brand'])): ?>
  <?php if (isset($_GET['logofail'])): ?>
  <div class="alert">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
    <span>Logo 写入失败：log.png 可能正被其他程序占用，或站点目录对 PHP 不可写。请关闭正在预览该图片的窗口后重试。</span>
  </div>
  <?php elseif (isset($_GET['logobad'])): ?>
  <div class="alert">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
    <span>Logo 未更新：仅支持 PNG / JPG / GIF，且文件大小不超过 2MB。</span>
  </div>
  <?php else: ?>
  <div class="alert ok">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
    <span><?= isset($_GET['logook']) ? '系统名称与 Logo 已保存：浏览器标签上的标题和图标都已更新（若标签图标仍是旧图，按 Ctrl + F5 强制刷新一次即可）。' : '系统名称已保存，浏览器标签标题已同步更新。' ?></span>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <?php if (isset($_GET['saved'])): ?>
  <div class="alert ok">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
    <span>已添加常用人员，并已自动填入输入框，可继续修改或直接开票。</span>
  </div>
  <?php endif; ?>
  <?php if (isset($_GET['pdel'])): ?>
  <div class="alert ok">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
    <span>已从常用人员中删除，已开票据上的姓名不受影响。</span>
  </div>
  <?php endif; ?>
  <?php if (isset($_GET['imported'])):
    $repFile = __DIR__ . '/import_report.json';
    $rep = is_file($repFile) ? json_decode((string)file_get_contents($repFile), true) : null;
    if (is_file($repFile)) { @unlink($repFile); }
    if (is_array($rep)):
      $errArr = array_values((array)($rep['err'] ?? []));
      $errTxt = trim(implode('；', $errArr));
  ?>
  <div class="alert <?= ($errTxt === '' && (int)($rep['ok'] ?? 0) >= 0) ? 'ok' : '' ?>">
    <?php if ($errTxt === ''): ?>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
    <?php else: ?>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
    <?php endif; ?>
    <span>导入完成：成功 <b><?= (int)($rep['ok'] ?? 0) ?></b> 张，跳过（已存在）<b><?= (int)($rep['skip'] ?? 0) ?></b> 张<?= $errTxt !== '' ? '，失败 ' . count($errArr) . ' 个：' . htmlspecialchars($errTxt) : '。' ?></span>
  </div>
  <?php endif; endif; ?>

  <section class="card" id="addform">
    <div class="card-head">
      <span class="bar"></span>
      <h2>新增送（销）货单</h2>
      <span class="head-tag">开票时间自动取服务器北京时间；开票后信息不可修改，可删除</span>
    </div>
    <form method="post" target="_blank">
      <div class="grid2">
        <div class="field">
          <span class="label">收货单位（人）<i>*</i></span>
          <input type="text" name="buyer" placeholder="请输入收货单位（人）" required value="">
        </div>
        <div class="field">
          <span class="label">地址、电话</span>
          <input type="text" name="address" placeholder="请输入地址、电话" value="">
        </div>
      </div>
      <div class="grid2">
        <div class="field">
          <span class="label">开票时间（北京时间，自动获取，不可修改）</span>
          <?php $nowTime = str_replace(' ', 'T', date('Y-m-d H:i:s')); ?>
          <input type="datetime-local" value="<?= htmlspecialchars($nowTime) ?>" readonly>
        </div>
      </div>
      <div class="grid2">
        <div class="field">
          <span class="label">制票人</span>
          <div class="pick-row">
            <?php
            // 默认取常用名单第一位；刚添加的人员（saved_type/saved_name）自动填入
            $defMaker = $people['makers'][0] ?? '';
            if (isset($_GET['saved_type'], $_GET['saved_name']) && $_GET['saved_type'] === 'maker' && (string)$_GET['saved_name'] !== '') {
                $defMaker = (string)$_GET['saved_name'];
            }
            ?>
            <input type="text" name="maker" placeholder="可随意填写，留空默认 xxx" value="<?= htmlspecialchars($defMaker) ?>" title="默认取常用第一位，可直接修改">
            <select onchange="fillPick(this, 'maker')" title="从常用制票人中选择">
              <option value="">常用…</option>
              <?php foreach ($people['makers'] as $n): ?>
              <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn gray" onclick="addPeople('maker')" title="保存常用制票人，下次开票直接选择">+ 添加</button>
          </div>
          <div class="people-tags">
            <?php if (empty($people['makers'])): ?>
              <em class="tag-empty">暂无常用制票人，点「+ 添加」保存名字</em>
            <?php else: foreach ($people['makers'] as $n): ?>
              <span class="ptag"><?= htmlspecialchars($n) ?><button type="button" class="ptag-x" title="从常用制票人中删除（不影响已开票据）" data-type="maker" data-name="<?= htmlspecialchars($n) ?>" onclick="delPeople(this.dataset.type, this.dataset.name)">×</button></span>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <div class="field">
          <span class="label">送货人</span>
          <div class="pick-row">
            <?php
            $defDeliverer = $people['deliverers'][0] ?? '';
            if (isset($_GET['saved_type'], $_GET['saved_name']) && $_GET['saved_type'] === 'deliverer' && (string)$_GET['saved_name'] !== '') {
                $defDeliverer = (string)$_GET['saved_name'];
            }
            ?>
            <input type="text" name="deliverer" placeholder="可随意填写，留空默认 xxx" value="<?= htmlspecialchars($defDeliverer) ?>" title="默认取常用第一位，可直接修改">
            <select onchange="fillPick(this, 'deliverer')" title="从常用送货人中选择">
              <option value="">常用…</option>
              <?php foreach ($people['deliverers'] as $n): ?>
              <option value="<?= htmlspecialchars($n) ?>"><?= htmlspecialchars($n) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn gray" onclick="addPeople('deliverer')" title="保存常用送货人，下次开票直接选择">+ 添加</button>
          </div>
          <div class="people-tags">
            <?php if (empty($people['deliverers'])): ?>
              <em class="tag-empty">暂无常用送货人，点「+ 添加」保存名字</em>
            <?php else: foreach ($people['deliverers'] as $n): ?>
              <span class="ptag"><?= htmlspecialchars($n) ?><button type="button" class="ptag-x" title="从常用送货人中删除（不影响已开票据）" data-type="deliverer" data-name="<?= htmlspecialchars($n) ?>" onclick="delPeople(this.dataset.type, this.dataset.name)">×</button></span>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
      <div class="card-head sub">
        <span class="bar orange"></span>
        <h2>商品明细</h2>
      </div>
      <div id="itemsWrap">
        <?php for ($r = 0; $r < 5; $r++): ?>
            <div class="row-item">
                <input class="w-name" type="text" name="item_name[]" placeholder="货物名称">
                <input class="w-unit" type="text" name="item_unit[]" placeholder="单位">
                <input class="w-num" type="number" step="0.01" name="item_qty[]" placeholder="数量" oninput="calcRow(this)">
                <input class="w-num" type="number" step="0.01" name="item_price[]" placeholder="单价" oninput="calcRow(this)">
                <input class="w-amt" type="text" placeholder="金额" readonly>
                <input class="w-num" type="text" name="item_note[]" placeholder="备注">
                <button type="button" class="w-del" onclick="delRow(this)" title="删除此行">×</button>
            </div>
        <?php endfor; ?>
      </div>
      <button type="button" class="btn add-row-btn" onclick="addRow()">+ 添加一行</button>
      <div class="total-bar">
        <span class="t-label">合计金额</span>
        <b id="totalShow">￥0.00</b>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn primary" name="add">保存并生成送（销）货单</button>
        <a href="?export=1" class="btn green" title="导出全部历史单据为 CSV">导出CSV（全部）</a>
      </div>
    </form>

    <!-- 常用人员添加表单（点「+ 添加」按钮时提交，保存后回到本页） -->
    <form id="peopleForm" method="post" action="ticket.php" style="display:none">
      <input type="hidden" name="save_people" value="1">
      <input type="hidden" id="pfType" name="people_type" value="">
      <input type="hidden" id="pfName" name="people_name" value="">
    </form>
    <!-- 常用人员删除表单（点人员标签上的 × 时提交；只移除下拉选项，不改已开票据） -->
    <form id="peopleDelForm" method="post" action="ticket.php#addform" style="display:none">
      <input type="hidden" name="del_people" value="1">
      <input type="hidden" id="pdType" name="people_type" value="">
      <input type="hidden" id="pdName" name="people_name" value="">
    </form>
    <script>
    // 从常用下拉框选择后填入输入框
    function fillPick(sel, target){
      if(sel.value){
        var input = document.querySelector('input[name="' + target + '"]');
        if(input){ input.value = sel.value; }
        sel.value = '';
      }
    }
    // 点「+ 添加」：输入姓名后保存到常用名单
    function addPeople(type){
      var label = (type === 'maker') ? '制票人' : '送货人';
      var name = prompt('请输入要添加的常用' + label + '姓名：');
      if(name === null) return;
      name = name.trim();
      if(!name){ alert('姓名不能为空'); return; }
      document.getElementById('pfType').value = type;
      document.getElementById('pfName').value = name;
      document.getElementById('peopleForm').submit();
    }
    // 点人员标签上的 ×：删除该常用人员（离职清理）
    function delPeople(type, name){
      var label = (type === 'maker') ? '制票人' : '送货人';
      if(!name) return;
      if(!confirm('确定从常用' + label + '中删除「' + name + '」？\n\n仅从下拉选项移除，已开票据上的姓名不会改变。')) return;
      document.getElementById('pdType').value = type;
      document.getElementById('pdName').value = name;
      document.getElementById('peopleDelForm').submit();
    }
    </script>
  </section>

  <section class="card" id="history">
    <div class="card-head">
      <span class="bar green"></span>
      <h2>历史送（销）货单</h2>
      <span class="head-tag" id="hisCount"><?php if ($searchKw !== ''): ?>找到 <?= $matchedCount ?> 张 / 共 <?= $historyTotal ?> 张 <?php else: ?>最近 <?= min(HISTORY_PAGE_SIZE, $matchedCount) ?> 张 / 共 <?= $historyTotal ?> 张<?php endif; ?></span>
      <button type="button" class="btn green sm" style="margin-left:8px" onclick="toggleImport()" title="把已开好的票据（.html 文件）导入系统">⇪ 导入票据</button>
      <!-- 导出 CSV：紧挨「导入票据」，按开票时间选区间，留空=全部 -->
      <form method="get" class="export-form" title="按开票时间导出，留空则导出全部">
        <input type="date" name="from" aria-label="起始日期" title="起始日期（含当天）">
        <span class="dash">至</span>
        <input type="date" name="to" aria-label="结束日期" title="结束日期（含当天）">
        <button type="submit" name="export" value="1" class="btn green sm">导出 CSV</button>
      </form>
      <button type="submit" form="batchDelForm" class="btn red sm" style="margin-left:8px" id="delSelBtn" disabled
              onclick="return confirmDelSel()" title="勾选左侧复选框后，可一次性删除多张">🗑 删除所选</button>
    </div>
    <!-- 批量删除表单：行内复选框通过 form 属性归属到这里，局部刷新后仍然有效 -->
    <form id="batchDelForm" method="post" autocomplete="off">
      <input type="hidden" name="del_batch" value="1">
    </form>
    <!-- 导入票据面板：支持上传已开好的票据文件，或一键扫描「票据留存」目录 -->
    <div id="importPanel" style="display:none;background:var(--green-l);border:1px solid #bbf7d0;border-radius:12px;padding:16px 18px;margin-bottom:16px">
      <form method="post" action="ticket.php#history" enctype="multipart/form-data">
        <input type="hidden" name="import_tickets" value="1">
        <div class="field">
          <span class="label">选择已开好的票据文件（.html，可按住 Ctrl / Shift 多选）</span>
          <input type="file" name="ticket_files[]" multiple accept=".html,text/html">
        </div>
        <div class="form-actions">
          <button type="submit" class="btn primary">导入所选票据</button>
          <button type="submit" class="btn green" name="import_scan" value="1">或：扫描「票据留存」目录一键导入</button>
          <button type="button" class="btn gray" onclick="toggleImport()">关闭</button>
        </div>
        <p class="foot-note">导入说明：送（销）货单号已存在的自动跳过，不会重复导入；上传的票据会归档到 <b>票据留存 / 开票日期 /</b> 目录；导入的票据同样<b>锁定不可修改，仅可删除</b>；无法识别单号的文件会单独提示，不影响其他票据。</p>
      </form>
    </div>
    <form method="get" class="searchbar" action="ticket.php#history">
      <div class="search-input">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input type="text" name="kw" placeholder="输入送（销）货单号或收货单位搜索..." value="<?= htmlspecialchars($searchKw) ?>">
      </div>
      <button type="submit" class="btn primary">搜索</button>
      <?php if ($searchKw): ?><a href="ticket.php#history" class="btn gray" onclick="saveScroll()">清除</a><?php endif; ?>
    </form>
    <?php if (empty($displayList)): ?>
      <?php if ($searchKw): ?>
        <div class="empty">没有找到符合条件的送（销）货单，换个关键词试试</div>
      <?php else: ?>
        <div class="empty">暂无记录，先在上方新增一张送（销）货单吧</div>
      <?php endif; ?>
    <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th class="sel"><input type="checkbox" id="chkAll" title="全选本页单据"></th>
          <th>送（销）货单号</th>
          <th>系统存单时间</th>
          <th>开票时间 ↓</th>
          <th>收货单位</th>
          <th>合计金额</th>
          <th>大写</th>
          <th style="min-width:390px">操作</th>
        </tr>
      </thead>
      <tbody id="hisBody">
        <?php foreach ($displayList as $item) { echo renderHistoryRow($item); } ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p class="foot-note">列表按<b>开票时间从高到低</b>排列，默认只显示<b>最近 10 张</b>，用上方搜索框输入<b>单号或收货单位</b>可查到全部历史票据；点<b>「复制重开」</b>会把该单的收货单位、货物名称、数量、单价、备注等自动填入上方新单，<b>各项都能继续修改</b>后保存为新票（单号与开票时间重新生成）；票据文件自动保存到 <b>票据留存 / 日期 /</b> 文件夹，点「打开位置」可在资源管理器中定位该文件；<b>已开票信息一经保存即锁定，不可更改（仅可删除）</b>；顶部今日/累计金额均按<b>开票时间</b>统计，与导入时间无关。</p>
  </section>
</div>
<div id="copyToast"></div>
<script>
/* 历史单据的可编辑字段（供「复制重开」回填表单） */
var TICKET_FILL = <?= json_encode($ticketFillData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function toast(msg){
    var t = document.getElementById('copyToast');
    if(!t) return;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.classList.remove('show'); }, 2200);
}
/* 局部刷新历史列表：保存新单后由打印页通知，直接更新表格与统计，无需整页刷新 */
/* ===== 批量删除：勾选左侧复选框，可一次删多张 ===== */
function refreshSel(){
    var boxes   = document.querySelectorAll('.rowchk');
    var checked = document.querySelectorAll('.rowchk:checked');
    var btn = document.getElementById('delSelBtn');
    if(btn){
        btn.disabled = checked.length === 0;
        btn.textContent = checked.length > 0 ? ('🗑 删除所选（' + checked.length + '）') : '🗑 删除所选';
    }
    var all = document.getElementById('chkAll');
    if(all){
        all.checked = boxes.length > 0 && checked.length === boxes.length;
        all.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
}
function toggleAllSel(on){
    var boxes = document.querySelectorAll('.rowchk');
    for(var i = 0; i < boxes.length; i++){ boxes[i].checked = on; }
    refreshSel();
}
function confirmDelSel(){
    var n = document.querySelectorAll('.rowchk:checked').length;
    if(!n){ alert('请先勾选要删除的单据'); return false; }
    return confirm('确定删除选中的 ' + n + ' 张送（销）货单？\n票据文件会一并移除，删除后不可恢复！');
}
document.addEventListener('change', function(e){
    var t = e.target;
    if(!t) return;
    if(t.id === 'chkAll'){ toggleAllSel(t.checked); return; }
    if(t.classList && t.classList.contains('rowchk')){ refreshSel(); }
});
document.addEventListener('DOMContentLoaded', refreshSel);
function refreshHistory(){
    var kwEl = document.querySelector('.searchbar input[name="kw"]');
    var url = 'ticket.php?partial=1';
    if(kwEl && kwEl.value.trim() !== ''){ url += '&kw=' + encodeURIComponent(kwEl.value.trim()); }
    fetch(url, {credentials: 'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            var body = document.getElementById('hisBody');
            // 首单场景：原本显示“暂无记录”、没有表格，直接整页刷新即可
            if(!body && d.total > 0){ location.reload(); return; }
            if(body){ body.innerHTML = d.rows || ''; }
            var c0 = document.getElementById('statTotal0');   if(c0) c0.textContent = d.total;
            var c1 = document.getElementById('statTodayCount'); if(c1) c1.textContent = d.todayCount;
            var c2 = document.getElementById('statTodayTotal'); if(c2) c2.textContent = '￥' + d.todayTotal;
            var c3 = document.getElementById('statTotalAll');   if(c3) c3.textContent = '￥' + d.totalAll;
            var hc = document.getElementById('hisCount');
            if(hc){
                hc.textContent = (d.kw ? ('找到 ' + d.matched + ' 张 / 共 ' + d.total + ' 张')
                                       : ('最近 ' + d.count + ' 张 / 共 ' + d.total + ' 张'));
            }
            if(d.fill){ TICKET_FILL = d.fill; }
            if(typeof refreshSel === 'function'){ refreshSel(); }
            toast('新送（销）货单已保存，历史列表已更新');
        })
        .catch(function(){ /* 极端情况下退化为整页刷新 */ location.reload(); });
}
window.addEventListener('message', function(e){
    if(e && e.data && e.data.type === 'ticket-saved'){ refreshHistory(); }
});
// 备份通道：个别浏览器新标签页拿不到 opener 时，通过 localStorage 事件同步
window.addEventListener('storage', function(e){
    if(e && e.key === 'ticket_saved_at'){ refreshHistory(); }
});

/* ===== 复制重开：把历史单据的内容填进「新增送（销）货单」表单，全部字段可再编辑 ===== */
function buildItemRow(it){
    var row = document.createElement('div');
    row.className = 'row-item';
    row.innerHTML =
        '<input class="w-name" type="text" name="item_name[]" placeholder="货物名称">' +
        '<input class="w-unit" type="text" name="item_unit[]" placeholder="单位">' +
        '<input class="w-num" type="number" step="0.01" name="item_qty[]" placeholder="数量" oninput="calcRow(this)">' +
        '<input class="w-num" type="number" step="0.01" name="item_price[]" placeholder="单价" oninput="calcRow(this)">' +
        '<input class="w-amt" type="text" placeholder="金额" readonly>' +
        '<input class="w-num" type="text" name="item_note[]" placeholder="备注">' +
        '<button type="button" class="w-del" onclick="delRow(this)" title="删除此行">×</button>';
    var qty = parseFloat(it.qty), price = parseFloat(it.price);
    row.querySelector('[name="item_name[]"]').value  = it.name  || '';
    row.querySelector('[name="item_unit[]"]').value  = it.unit  || '';
    row.querySelector('[name="item_qty[]"]').value   = (it.qty === '' || it.qty === null) ? '' : (isNaN(qty) ? it.qty : qty);
    row.querySelector('[name="item_price[]"]').value = (it.price === '' || it.price === null) ? '' : (isNaN(price) ? it.price : price);
    row.querySelector('[name="item_note[]"]').value  = it.note  || '';
    row.querySelector('.w-amt').value = (isNaN(qty) || isNaN(price)) ? '' : (qty * price).toFixed(2);
    return row;
}
function fillFromTicket(no){
    var d = (window.TICKET_FILL && TICKET_FILL[no]) ? TICKET_FILL[no] : null;
    if(!d){ alert('未找到该单据内容'); return; }
    // 收货单位 / 地址电话 / 制票人 / 送货人 全部填入，仍可修改
    var f = document.querySelector('#addform form');
    if(!f){ alert('未找到新增单据表单，请刷新页面重试'); return; }
    f.querySelector('input[name="buyer"]').value     = d.buyer || '';
    f.querySelector('input[name="address"]').value   = d.address || '';
    f.querySelector('input[name="maker"]').value     = d.maker || '';
    f.querySelector('input[name="deliverer"]').value = d.deliverer || '';
    // 明细整体重建：货物名称、单位、数量、单价、备注都可继续编辑
    var wrap = document.getElementById('itemsWrap');
    wrap.innerHTML = '';
    var list = (d.items && d.items.length) ? d.items : [{name:'',unit:'',qty:'',price:'',note:''}];
    var need = Math.max(list.length, 5);
    for(var i = 0; i < need; i++){
        wrap.appendChild(buildItemRow(list[i] || {name:'',unit:'',qty:'',price:'',note:''}));
    }
    calcTotal();
    var card = document.getElementById('addform');
    if(card){
        card.scrollIntoView({behavior:'smooth', block:'start'});
        card.classList.remove('flash');
        void card.offsetWidth;
        card.classList.add('flash');
    }
    toast('已把这张单的内容填入新单，可修改后保存');
}
</script>
<script>
function addRow(){
    var wrap = document.getElementById('itemsWrap');
    var row = document.createElement('div');
    row.className = 'row-item';
    row.innerHTML = `
        <input class="w-name" type="text" name="item_name[]" placeholder="货物名称">
        <input class="w-unit" type="text" name="item_unit[]" placeholder="单位">
        <input class="w-num" type="number" step="0.01" name="item_qty[]" placeholder="数量" oninput="calcRow(this)">
        <input class="w-num" type="number" step="0.01" name="item_price[]" placeholder="单价" oninput="calcRow(this)">
        <input class="w-amt" type="text" placeholder="金额" readonly>
        <input class="w-num" type="text" name="item_note[]" placeholder="备注">
        <button type="button" class="w-del" onclick="delRow(this)">×</button>`;
    wrap.appendChild(row);
    calcTotal();
}
function delRow(btn){
    var row = btn.parentElement;
    if(document.querySelectorAll('.row-item').length > 1){
        row.remove();
    }
    calcTotal();
}
function calcRow(input){
    var row = input.parentElement;
    var qty = parseFloat(row.querySelector('[name="item_qty[]"]').value) || 0;
    var price = parseFloat(row.querySelector('[name="item_price[]"]').value) || 0;
    var amt = qty * price;
    row.querySelector('.w-amt').value = amt.toFixed(2);
    calcTotal();
}
function calcTotal(){
    var total = 0;
    document.querySelectorAll('.w-amt').forEach(function(el){
        total += parseFloat(el.value) || 0;
    });
    document.getElementById('totalShow').textContent = '￥' + total.toFixed(2);
}
// 页面加载自动算一次合计
window.addEventListener('load', function(){
    calcTotal();
});
// 搜索/清除后停留在历史单据位置，不跳回顶部
function saveScroll(){
    try{ sessionStorage.setItem('ticket_scroll', String(window.scrollY)); }catch(e){}
}
var searchForm = document.querySelector('.searchbar');
if(searchForm){
    searchForm.addEventListener('submit', saveScroll);
}
window.addEventListener('pageshow', function(){
    var y = sessionStorage.getItem('ticket_scroll');
    if(y !== null && y !== ''){
        sessionStorage.removeItem('ticket_scroll');
        window.scrollTo(0, parseInt(y, 10) || 0);
    }
});
// 导入票据面板开关
function toggleImport(){
    var p = document.getElementById('importPanel');
    if(p){ p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'block' : 'none'; }
}
// 注意：不要在表单 submit 后用 setTimeout(location.reload) 刷新本页——
// 那会中断尚未完成的 POST（如 Logo 上传）。新增单据后由查看页自动通知本页刷新。
</script>
</body>
</html>
