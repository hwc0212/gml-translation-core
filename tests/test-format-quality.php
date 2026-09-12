<?php
/** Offline quality checks: no WordPress state, network or translation writes. */
define('ABSPATH', __DIR__);
require_once __DIR__ . '/../src/class-translation-ai-client.php';
require_once __DIR__ . '/../src/class-translation-text.php';
$reflection = new ReflectionClass('GML_Translation_AI_Client');
$client = $reflection->newInstanceWithoutConstructor();
$check = $reflection->getMethod('check_translation_quality');
$check->setAccessible(true);
$cases = [
    ['95% or 100% color removal.', '95 % oder 100 % Farbentfernung.', true],
    ['An increase of over 20% compared to previous models.', 'Une augmentation de plus de 20 % par rapport aux modeles precedents.', true],
    ['95% of color; 20% efficiency.', '95 % der Farbe; 20 % Effizienz.', true],
    ['Removal: 98.8%.', 'Entfernung: 98,8 %.', true],
    ['20% off', '20\u{00a0}% de reduction', true],
    ['100% safe', '100\u{202f}% sicher', true],
    ['At 95% or 100%, use %s.', 'Bei 95 % oder 100 %: %s.', true],
    ['Value % d.', 'Wert % d.', true],
    ['Value % d.', 'Wert %s.', false],
    ['Name %s count %d', 'Anzahl %d Name %s', false],
    ['Value %0.3f', 'Wert %0.2f', false],
    ['Name %1$s count %2$d %%', 'Anzahl %2$d Name %1$s %%', true],
    ['Name %1$s count %2$d %%', 'Anzahl %2$d Name %1$s', false],
    ['Value %*.*f', 'Wert %*.*f', true],
    ['Value %*.*f', 'Wert %*.2f', false],
    ['Code 5%s', 'Code 5%d', false],
    ['95% or 100% removal', '90 % oder 100 % Entfernung', false],
    ['95% removal', 'Entfernung', false],
    ['95% removal', '95 % und 95 % Entfernung', false],
    ['Pump', 'Pumpe 95 %', false],
    ['95% or 100% removal', '100 % oder 95 % Entfernung', true],
    ['90*45*30mm', '90*45*30mm', true],
    ['90*45*30mm', '904530mm', false],
    ['{{name}} {value}', '{{name}} {value}', true],
    ['{{name}} {value}', '{{other}} {value}', false],
    ['https://example.test/private?token=secret', 'https://example.test/other', false],
];
foreach ($cases as $index => $case) {
    [$source, $target, $expected] = $case;
    $target = str_replace(['\\u{00a0}', '\\u{202f}'], ["\u{00a0}", "\u{202f}"], $target);
    $passed = true;
    try { $check->invoke($client, $source, $target); }
    catch (RuntimeException $e) {
        $passed = false;
        if (strpos($e->getMessage(), 'kind=') === false || strpos($e->getMessage(), 'first_mismatch=') === false) {
            throw new RuntimeException('Missing structural diagnostics: ' . $index);
        }
        if (strpos($e->getMessage(), 'secret') !== false || strpos($e->getMessage(), 'example.test') !== false) {
            throw new RuntimeException('Content leaked into diagnostics: ' . $index);
        }
    }
    if ($passed !== $expected) throw new RuntimeException('Quality fixture failed: ' . $index);
}
echo 'OK format quality cases=' . count($cases) . "\n";
