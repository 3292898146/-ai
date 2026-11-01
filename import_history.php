<?php
/**
 * ============================================================
 *  若琳AI送（销）货单管理系统 —— 历史票据导入脚本
 * ============================================================
 *  作用：扫描「票据留存」目录下所有子目录中的 .html 票据文件，
 *        解析内容后合并进 ticket_data.json
 *  特点：可重复执行——已存在的送（销）货单号自动跳过，不会重复导入
 *
 *  调用方式（命令行，需在脚本所在目录执行）：
 *    php import_history.php
 * ============================================================
 */

date_default_timezone_set('Asia/Shanghai');

$ticketRoot = __DIR__ . '/票据留存';
$jsonFile   = __DIR__ . '/ticket_data.json';

if (!file_exists($jsonFile)) {
    file_put_contents($jsonFile, json_encode([], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
$list = json_decode(file_get_contents($jsonFile), true);
if (!is_array($list)) $list = [];

// 记录已存在的单号，避免重复导入；同时计算最大 id（自增唯一，避免 (int)单号 在 32 位 PHP 溢出重复）
$existingNos = [];
$maxId = 0;
foreach ($list as $t) {
    $existingNos[$t['ticket_no']] = true;
    $maxId = max($maxId, (int)($t['id'] ?? 0));
}
$nextId = max($maxId, time());

// 递归收集所有 html 文件
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ticketRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'html') {
        $files[] = $f->getPathname();
    }
}
sort($files);

/** 去除 HTML 标签并转回普通文本 */
function clean($s)
{
    return trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'));
}

$imported = 0;
$skipped  = 0;
$errors   = [];

foreach ($files as $file) {
    $html  = file_get_contents($file);
    $mtime = date('Y-m-d H:i:s', filemtime($file));

    // 送（销）货单号（兼容旧版「送货单号」标签）
    if (!preg_match('/(?:送（销）货单号|送货单号)：(\d+)/u', $html, $mNo)) {
        $errors[] = basename($file) . '：未找到送（销）货单号';
        continue;
    }
    $ticketNo = $mNo[1];

    if (isset($existingNos[$ticketNo])) { $skipped++; continue; }

    // 右侧时间：可能是「开票时间：YYYY-mm-dd HH:ii:ss」或仅「YYYY年m月d日」
    $invoiceTime = $mtime;
    $dateCn = '';
    if (preg_match('/<span class="right">开票时间：([\d\- :]+)<\/span>/u', $html, $mT)) {
        $invoiceTime = trim($mT[1]);
    } elseif (preg_match('/<span class="right">(\d{4}年\d{1,2}月\d{1,2}日)<\/span>/u', $html, $mD)) {
        $dateCn = $mD[1];
        $invoiceTime = $mtime;
    }
    if ($dateCn === '') $dateCn = date('Y年m月d日', strtotime($invoiceTime));

    // 收货单位 / 地址、电话
    $buyer = preg_match('/收货单位：(.+?)<\/span>/u', $html, $mB) ? clean($mB[1]) : '';
    $address = preg_match('/地址、电话：(.*?)<\/span>/u', $html, $mA) ? clean($mA[1]) : '';

    // 制票人 / 送货人（票据底部）
    $maker = preg_match('/制票：(.+?)<\/span>/u', $html, $mM) ? clean($mM[1]) : '';
    $deliverer = preg_match('/送货人：(.+?)<\/span>/u', $html, $mD) ? clean($mD[1]) : '';

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
                    'qty'    => (float)$c[2],
                    'price'  => (float)$c[3],
                    'amount' => round((float)$c[4], 2),
                    'note'   => $c[5],
                ];
            }
        }
    }

    // 合计金额与小写数字
    $total = 0.0;
    if (preg_match('/class="rmb">￥([\d,]+\.\d{2})<\/td>/u', $html, $mT2)) {
        $total = (float)str_replace(',', '', $mT2[1]);
    }
    $totalCn = preg_match('/class="num-unit">([^<]+)<\/td>/u', $html, $mC) ? clean($mC[1]) : '';

    $rec = [
        'id'              => ++$nextId,
        'ticket_no'       => $ticketNo,
        'create_time'     => $mtime,
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
    ];
    $list[] = $rec;
    $existingNos[$ticketNo] = true;
    $imported++;
}

file_put_contents($jsonFile, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "扫描文件数：" . count($files) . "\n";
echo "新增导入：{$imported} 张\n";
echo "跳过（已存在）：{$skipped} 张\n";
if ($errors) echo "异常：\n" . implode("\n", $errors) . "\n";
echo "当前送（销）货单总数：" . count($list) . "\n";
