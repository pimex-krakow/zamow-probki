<?php
require __DIR__.'/fixture.php';
$items=[];
for($i=1;$i<=130;$i++){
 $items[]=['id'=>$i,'parent'=>$i,'key'=>'sample:'.$i,'label'=>'Model '.$i.' — Szary','title'=>'Model '.$i,'brand'=>$i%2?'PIMEX':'Inny producent',
 'color'=>$i%2?'Szary':'Brązowy','group'=>['tarasy','elewacje','lamele'][$i%3],'available'=>$i!==130,'image_url'=>'https://example.com/img.svg','url'=>'https://example.com/produkt/'.$i,
 'widths'=>[145.0],'thicknesses'=>[25.0],'lengths'=>$i%2?[3000.0]:[4000.0],'custom_length'=>$i===129,'shades'=>[$i%2?'grey':'brown'],
 'materials'=>[$i%2?'Drewno':'Kompozyt'],'surfaces'=>['Ryflowana'],'construction'=>$i%2?'solid':'hollow','coating'=>'','attrs'=>['attr_pa_test'=>[$i%2?'Opcja A':'Opcja B']],
 'aliases'=>[$i,$i+1000], 'unit_options'=>$i===1?[['mb'=>10.0,'m2'=>1000.0],['mb'=>100.0,'m2'=>100.0]]:[['mb'=>($i+100.0),'m2'=>($i+200.0)]]];
}
$config=['items'=>$items,'enabled'=>true,'max'=>3,'price'=>4,'shipping'=>14,'picks'=>[],'storage'=>'pimex-samples-v5-1',
 'fields'=>['brand'=>'Producent','shade'=>'Kolor','material'=>'Materiał','construction'=>'Konstrukcja','length'=>'Długość deski','price_mb'=>'Cena deski za mb','price_m2'=>'Cena deski za m²','attr_pa_test'=>'Atrybut testowy']];
$o=['page'=>5];ob_start();include __DIR__.'/../../pimex-probki-v5/templates/selector.php';$html=ob_get_clean();
$html='<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Arial;margin:0}main{max-width:1400px;margin:auto;padding:24px 16px}</style><main>'.$html.'</main></html>';
file_put_contents('/tmp/pimex-v5-fixture.html',$html);
echo 'Browser fixture bytes: '.strlen($html)."\n";
