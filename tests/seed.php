<?php
// Dane testowe: dwa lokale, admin, właściciele, pracownicy z notatkami i kontaktami
$root = $argv[1]; $G = "<?php exit; ?>\n";
$w = fn($f, $d) => file_put_contents($f, $G . json_encode($d, JSON_UNESCAPED_UNICODE));
@mkdir("$root/app/data", 0777, true); @mkdir("$root/nadarzyn/data", 0777, true);
$h = password_hash('haslo-testowe-1', PASSWORD_DEFAULT);
$w("$root/app/data/users.php", [
  ['id'=>'uadmin0001','email'=>'admin@test.pl','name'=>'Admin','hash'=>$h,'role'=>'admin','status'=>'active','lokal'=>null],
  ['id'=>'uowner000a','email'=>'a@test.pl','name'=>'Właściciel A','hash'=>$h,'role'=>'owner','status'=>'active','lokal'=>'lokal-a'],
  ['id'=>'uowner000b','email'=>'b@test.pl','name'=>'Właściciel B','hash'=>$h,'role'=>'owner','status'=>'active','lokal'=>'lokal-b'],
]);
foreach (['a','b'] as $x) {
  $w("$root/app/data/lokal-lokal-$x.php", ['slug'=>"lokal-$x",'name'=>"Lokal $x",'city'=>'','status'=>'active','owner'=>"uowner000$x",
    'positions'=>[['id'=>"pkuch$x",'name'=>'Kuchnia','short'=>'K','count'=>1]],
    'staff'=>[
      ['id'=>"sanna$x",'name'=>'Anna','pin'=>"111$x"==="111a"?'1111':'2222','positions'=>["pkuch$x"],'min'=>null,'max'=>null,'noWd'=>[6],'picksOwn'=>false,'manager'=>false,'active'=>true,'email'=>"anna$x@test.pl",'phone'=>'600100200'],
      ['id'=>"sbartek$x",'name'=>'Bartek','pin'=>$x==='a'?'3333':'4444','positions'=>["pkuch$x"],'min'=>5,'max'=>8,'noWd'=>[],'picksOwn'=>false,'manager'=>false,'active'=>true],
      ['id'=>"smanag$x",'name'=>'Marta','pin'=>$x==='a'?'5555':'6666','positions'=>["pkuch$x"],'min'=>null,'max'=>null,'noWd'=>[],'picksOwn'=>false,'manager'=>true,'active'=>true],
    ],
    'rules'=>['maxRun'=>5,'openWd'=>[0,1,2,3,4,5,6],'pairs'=>[],'apart'=>[['a'=>"sanna$x",'b'=>"sbartek$x"]]]]);
  $w("$root/app/data/m-lokal-$x-2030-01.php", ['prefs'=>["sbartek$x"=>['person'=>"sbartek$x",'want'=>[],'blocked'=>[3],'note'=>"SEKRET-$x"]],'plan'=>null,'closed'=>[],'deadline'=>null]);
}
copy("$root/nadarzyn/config.example.php", "$root/nadarzyn/config.php");
