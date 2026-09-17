<?php
/** Offline quality checks: no WordPress state, network or translation writes. */
define('ABSPATH', __DIR__);
$core=getenv('GML_TEST_CORE_SRC')?:__DIR__.'/../src';
require_once $core.'/class-translation-ai-client.php';
require_once $core.'/class-translation-text.php';
$reflection = new ReflectionClass('GML_Translation_AI_Client');
$client = $reflection->newInstanceWithoutConstructor();
$check = $reflection->getMethod('check_translation_quality');
$check->setAccessible(true);
$cases = [
    ["10 x\n20 mm", '10 x 20 mm', true],
    ["Value %'_10s", 'Value', false],
    ["Value %'_10s", "Value %'-10s", false],
    ["Value %'_10s", "Wert %'_10s", true],
    ['Name %1$s', 'Name %2$s', false],
    ['Name %s %%', 'Name %s', false],
    ['<b title="a">Value</b>', '<b title="a">Wert</b>', true],
    ['<b title="a">Value</b>', '<b title="b">Wert</b>', false],
    ['<b>Value</b>', 'Wert', false],
    ['Model AB-123', 'Model AB-123', true],
    ['20% increase', '20％ increase', true],
    ['20% increase', '20٪ increase', true],
    ['20% increase', '21％ increase', false],
    ['10*20mm', '10 × 20 мм', true],
    ['10*20cm', '10 х 20 см', true],
    ['10*20mm', '10 × 20 см', false],
    ['10*20mm', '20 × 10 mm', false],
    ['10*20mm', '10 × 21 mm', false],
    ['Value %H', 'Value', false],
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
    ['90*45*30mm', '90 x 45 × 30 mm', true],
    ['90.5*45.2*30mm', '90,5 × 45,2 × 30 mm', true],
    ['90.5*45.2*30mm', '90,6 × 45,2 × 30 mm', false],
    ['90*45*30mm', '90 x 45 x 30 cm', false],
    ['{{name}} {value}', '{{name}} {value}', true],
    ['{{name}} {value}', '{{other}} {value}', false],
    ['https://example.test/private?token=secret', 'https://example.test/other', false],
];
$ozon_sources=[
    'A fixed ozone-to-dye ratio or ten-minute contact period does not guarantee 95% or 100% color removal. Dye type, initial load, background demand, pH and temperature all affect performance. Use representative bench or pilot tests to establish an operating range and allow for normal process variation.',
    'There is no universal ozone-to-dye ratio that guarantees 95% or 100% color removal. Required mass dose and contact time vary with the dye mix, initial color, pH, temperature, water flow and other oxidizable substances. Bench and pilot tests should establish the operating range and demonstrate the treatment endpoint.',
];
// Fixed offline candidates test format preservation, not live provider quality.
$ozon_candidates=[
    [
        'Una relación fija entre ozono y colorante o un contacto de diez minutos no garantiza eliminar el 95 % o el 100 % del color. El tipo de colorante, la carga inicial, la demanda de fondo, el pH y la temperatura afectan al rendimiento. Utilice ensayos de laboratorio o piloto representativos para establecer un intervalo operativo y prever la variación normal del proceso.',
        'Ein festes Ozon-Farbstoff-Verhältnis oder eine Kontaktzeit von zehn Minuten garantiert keine Farbentfernung von 95 % oder 100 %. Farbstoffart, Anfangsbelastung, Hintergrundbedarf, pH und Temperatur beeinflussen die Leistung. Repräsentative Labor- oder Pilotversuche sollen den Betriebsbereich festlegen und normale Prozessschwankungen berücksichtigen.',
        'Фиксированное соотношение озона и красителя или десятиминутный контакт не гарантирует удаление цвета на 95 % или 100 %. Тип красителя, начальная нагрузка, фоновая потребность, pH и температура влияют на результат. Репрезентативные лабораторные или пилотные испытания должны определить рабочий диапазон с учетом нормальных колебаний процесса.',
    ],
    [
        'No existe una relación universal entre ozono y colorante que garantice eliminar el 95 % o el 100 % del color. La dosis másica y el tiempo de contacto necesarios varían según la mezcla de colorantes, el color inicial, el pH, la temperatura, el caudal de agua y otras sustancias oxidables. Los ensayos de laboratorio y piloto deben establecer el intervalo operativo y demostrar el punto final del tratamiento.',
        'Es gibt kein universelles Ozon-Farbstoff-Verhältnis, das eine Farbentfernung von 95 % oder 100 % garantiert. Die erforderliche Massendosis und Kontaktzeit hängen von Farbstoffgemisch, Ausgangsfarbe, pH, Temperatur, Wasserdurchfluss und anderen oxidierbaren Stoffen ab. Labor- und Pilotversuche sollen den Betriebsbereich festlegen und den Behandlungsendpunkt nachweisen.',
        'Не существует универсального соотношения озона и красителя, гарантирующего удаление цвета на 95 % или 100 %. Необходимая массовая доза и время контакта зависят от смеси красителей, исходного цвета, pH, температуры, расхода воды и других окисляемых веществ. Лабораторные и пилотные испытания должны определить рабочий диапазон и подтвердить конечную точку обработки.',
    ],
];
foreach($ozon_sources as $n=>$source) foreach($ozon_candidates[$n] as $candidate) $cases[]=[$source,$candidate,true];
if(getenv('GML_TEST_OZON_ONLY')==='1') $cases=array_slice($cases,-6);
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
