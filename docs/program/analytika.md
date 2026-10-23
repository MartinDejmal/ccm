# Analytika

Záložka **Analytika** nabízí grafy pro vybrané vozidlo, s filtrem období od–do (včetně hranic), měnou nákladů a médiem spotřeby. Funguje i pro archivovaná vozidla. Všechny grafy mají textové hodnoty a rozbalovací tabulku; bod/sloupec má popisek s přesnou hodnotou. Při nedostatku dat se ukáže vysvětlení místo nulové spotřeby.

## Rozložení nákladů

Prstencový graf a legenda ukazují kladné skutečné výdaje podle kategorií. Palivo a energie tvoří společnou kategorii; ostatní položky odpovídají číselníku výdajů, včetně pořizovací ceny. Podíl je vypočten z kladných nákladů v jediné zvolené měně. Měny se nepřepočítávají ani nesčítají. Změna média nemění finanční graf celého vozidla.

Vratky se nesmí kreslit jako záporná výseč: jsou uvedené samostatně, celkem i v tabulce po kategoriích. Střed prstence ukazuje celkovou částku po vratkách. Pokud existují jen vratky nebo žádné výdaje, prstenec se nezobrazuje; součty zůstávají dostupné. Vyřazené události ani plánované platby se nezapočítávají. Částky a součty počítá server přes DECIMAL/BigDecimal; desetinná čísla JavaScriptu slouží pouze k vykreslení geometrie.

## Spotřeba po měsících

Každé médium má vlastní spojnicový graf a jednotku, například l/100 km. Používá stejný výpočet jako záložka Spotřeba: úplné intervaly plná–plná, částečná tankování v uzavřeném intervalu, revize, vyřazené záznamy a přerušení historie. Importované Consumption ani palubní BC hodnoty nepřebírá jako vypočtenou spotřebu.

Do výsledku vstoupí pouze intervaly celé uvnitř vybraného období. Měsíc určuje datum uzavíracího tankování; interval překračující měsíc se nerozděluje odhadem. Nejde tedy o přesné měření jízd v kalendářním měsíci. Průměr je vážený: `100 × součet množství / součet vzdáleností`. Tabulka uvádí i pokrytou vzdálenost a počet intervalů. Chybějící měsíce se nenahrazují nulou ani nespojují čárou. Výpočet zahrnuje celou historii, nikoli jen stránku deníku.

Elektřina a AdBlue zobrazují **měsíční dodávky na vzdálenost** s výslovným upozorněním, že nejde o měření skutečné spotřeby baterie nebo nádrže. Médium v kg zachovává kg/100 km; vozidlo v mílích používá /100 mi, bez implicitního převodu.

## Histogram po půllitrech

Pro plná–plná v litrech se úplné intervaly rozdělí do pásem širokých 0,5 l/100 jednotek vzdálenosti. Dolní mez je včetně, horní bez: například `[5,0; 5,5)`. Každý interval má váhu jednoho pozorování, nikoli počet tankování nebo kilometrů. Zařazení používá přesný poměr množství a vzdálenosti před zaokrouhlením zobrazené spotřeby. Chybějící pásma mají nulovou četnost; osa zachovává číselné rozestupy. Elektřina, AdBlue a média mimo litry půllitrový histogram nemají.

## Doporučená další rozšíření

- Měsíční a kumulativní náklady, s rozlišením provozu a pořízení.
- Cena za litr v čase, samostatně podle paliva a měny.
- Náklady na km/mi pouze při doložené vzdálenosti a shodném období; vysvětlit rozdíl nákupu a spotřeby paliva.
- Meziroční a sezónní srovnání spotřeby.
- Výhled plánovaných plateb na příštích 12 měsíců, oddělený od skutečných nákladů.

Tato rozšíření jsou návrhy, nikoli již implementované grafy.

## API

`GET /api/v1/vehicles/{vehicle}/analytics?from=YYYY-MM-DD&to=YYYY-MM-DD&currency=CZK&energy_stream_id=…`

Datum a médium jsou volitelné; výchozí měna je měna vozidla. Přístup je omezen na vlastníka vozidla, médium musí patřit stejnému vozidlu. Odpověď obsahuje `costs` (kategorie, kladné výdaje, vratky a čistý součet) a oddělené `streams` s `monthly`, `histogram` a `sample_count`. Desetinné hodnoty jsou řetězce. API nemění data ani nepotřebuje novou migraci.
