<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Owner 27-11 06:43: every tracking number is a blue link to the carrier's own tracking page – parcel couriers, sea lines (container / B/L),
 * road and air. The carrier comes from the record (its carrier name or the carrier's own link pattern), else it is recognised from the number itself
 * (container prefix MAEU → Maersk, "…GB" → Royal Mail, "1Z…" → UPS…), else a page that tracks every carrier (17TRACK for parcels, SeaRates for
 * containers). Carriers are a list the Owner can extend in place ("+ Add carrier": name, kind, link with {n}, what its numbers look like).
 */
class TrackingLinks
{
    /** name, mode, link ({n} = the number), number rule (regex, no delimiters), other names it goes by */
    public const BUILT_IN = [
        ['Royal Mail', 'COURIER', 'https://www.royalmail.com/track-your-item#/tracking-results/{n}', '^[A-Z]{2}\d{9}GB$', 'royalmail,rm,click & drop,tracked 24,tracked 48,special delivery'],
        ['Parcelforce', 'COURIER', 'https://www.parcelforce.com/track-trace?trackNumber={n}', '^[A-Z]{2}\d{7}$|^[A-Z]{4}\d{10}$', 'parcel force,pf'],
        ['DPD', 'COURIER', 'https://track.dpd.co.uk/parcels/{n}', '^\d{14}$', 'dpd uk,dpd local'],
        ['Evri', 'COURIER', 'https://www.evri.com/track/parcel/{n}/details', '^[HTJ][A-Z0-9]{15}$', 'hermes,evri'],
        ['Yodel', 'COURIER', 'https://www.yodel.co.uk/tracking/{n}', '^JJD\d+$', 'yodel direct'],
        ['DHL', 'COURIER', 'https://www.dhl.com/gb-en/home/tracking/tracking-express.html?submit=1&tracking-id={n}', '^\d{10}$|^JD\d{18}$', 'dhl express,dhl parcel'],
        ['UPS', 'COURIER', 'https://www.ups.com/track?tracknum={n}', '^1Z[0-9A-Z]{16}$', 'ups'],
        ['FedEx', 'COURIER', 'https://www.fedex.com/fedextrack/?trknbr={n}', '^\d{12}$|^\d{15}$', 'fedex,tnt'],
        ['USPS', 'COURIER', 'https://tools.usps.com/go/TrackConfirmAction?tLabels={n}', '^9[2-5]\d{20}$', 'usps'],
        ['4PX', 'COURIER', 'https://track.4px.com/#/result/0/{n}', '^4PX\w+$', '4px'],
        ['Yanwen', 'COURIER', 'https://track.yw56.com.cn/en/querydel?nums={n}', '^(UG|VR|YW)\w+$', 'yanwen'],
        ['Cainiao / AliExpress', 'COURIER', 'https://global.cainiao.com/newDetail.htm?mailNoList={n}', '^(LP|CN|AE)\d{8,}\w*$', 'cainiao,aliexpress standard'],
        ['Maersk', 'SEA', 'https://www.maersk.com/tracking/{n}', '^(MAEU|MSKU|MRKU|MRSU|SEAU|SUDU)\d{7}$', 'maersk,sealand,hamburg sud'],
        ['MSC', 'SEA', 'https://www.msc.com/en/track-a-shipment?agencyPath=msc&trackingNumber={n}', '^(MSCU|MEDU|MSDU|MSMU|TGHU)\d{7}$', 'msc,mediterranean shipping'],
        ['COSCO', 'SEA', 'https://elines.coscoshipping.com/ebusiness/cargoTracking?trackingType=CONTAINER&number={n}', '^(CSNU|CBHU|COSU|CCLU|OOLU|OOCU)\d{7}$', 'cosco,oocl'],
        ['CMA CGM', 'SEA', 'https://www.cma-cgm.com/ebusiness/tracking/search?SearchBy=Container&Reference={n}', '^(CMAU|CGMU|APHU|APZU|ECMU)\d{7}$', 'cma,apl'],
        ['Evergreen', 'SEA', 'https://www.searates.com/container/tracking/?number={n}', '^(EGHU|EMCU|EISU|EGSU|EITU)\d{7}$', 'evergreen,emc'],
        ['ONE (Ocean Network Express)', 'SEA', 'https://ecomm.one-line.com/one-ecom/manage-shipment/cargo-tracking?ctrack-field={n}', '^(ONEU|NYKU|MOLU|KKFU|TCLU)\d{7}$', 'one,ocean network express'],
        ['Hapag-Lloyd', 'SEA', 'https://www.hapag-lloyd.com/en/online-business/track/track-by-container-solution.html?container={n}', '^(HLCU|HLXU|HLBU|UACU)\d{7}$', 'hapag,hapag lloyd'],
        ['Any container / B/L (SeaRates)', 'SEA', 'https://www.searates.com/container/tracking/?number={n}', '^[A-Z]{4}\d{7}$', 'sea,container,b/l,bill of lading'],
        ['Air cargo (AWB, parcelsapp)', 'AIR', 'https://parcelsapp.com/en/tracking/{n}', '^\d{3}-?\d{8}$', 'air,awb,air waybill'],
        ['Any parcel (17TRACK)', 'COURIER', 'https://t.17track.net/en#nums={n}', null, '17track,other'],
    ];

    /** The list the screens use: [name, mode, url, rule, aliases[]] – the company's own first, then the built-in ones. */
    public static function catalogue(int $ent): array
    {
        return DB::table('cat_tracking_carriers')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderByDesc('fk_id_ent')->orderBy('sort_no')->orderBy('id')
            ->get(['id', 'name', 'mode', 'url_pattern as url', 'number_rule as rule', 'aliases'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'mode' => $c->mode, 'url' => $c->url, 'rule' => $c->rule, 'aliases' => array_values(array_filter(array_map('trim', explode(',', mb_strtolower((string) $c->aliases . ',' . $c->name)))))])->all();
    }

    /** [url, carrier name, how] for a number (and the carrier name the record has, when it has one). */
    public static function link(int $ent, string $no, ?string $carrier = null): ?array
    {
        $n = strtoupper(preg_replace('/\s+/', '', trim($no))); if ($n === '' || mb_strlen($n) > 60) return null;
        $cat = self::catalogue($ent); $fill = fn (string $u) => str_replace('{n}', rawurlencode($n), $u);
        if ($carrier !== null && trim($carrier) !== '') {
            $own = DB::table('t_carriers')->where('fk_id_ent', $ent)->whereRaw('LOWER(carrier_name) = ?', [mb_strtolower(trim($carrier))])->value('tracking_url_pattern');
            if ($own) return [str_replace(['{tracking}', '{n}'], rawurlencode($n), $own), trim($carrier), 'the carrier\'s own link'];
            $c = mb_strtolower($carrier);
            foreach ($cat as $x) foreach ($x['aliases'] as $a) if ($a !== '' && preg_match('/(^|[^a-z0-9])' . preg_quote($a, '/') . '([^a-z0-9]|$)/', $c)) return [$fill($x['url']), $x['name'], 'carrier ' . trim($carrier)];
        }
        foreach ($cat as $x) if ($x['rule'] && @preg_match('/' . str_replace('/', '\/', $x['rule']) . '/', $n)) return [$fill($x['url']), $x['name'], 'recognised from the number'];
        $any = collect($cat)->firstWhere('rule', null) ?? ['url' => 'https://t.17track.net/en#nums={n}', 'name' => '17TRACK'];
        return [$fill($any['url']), $any['name'], 'not recognised – a page that tracks every carrier'];
    }

    public static function add(int $ent, array $d, ?int $user): int
    {
        $name = trim((string) ($d['name'] ?? '')); $url = trim((string) ($d['url'] ?? '')); $mode = strtoupper((string) ($d['mode'] ?? 'COURIER'));
        if ($name === '') throw new \InvalidArgumentException('Give the carrier a name.');
        if (! preg_match('~^https://\S+\{n\}~', $url)) throw new \InvalidArgumentException('Paste the carrier\'s tracking link with {n} where the number goes, e.g. https://carrier.com/track?no={n}');
        if (! in_array($mode, ['COURIER', 'SEA', 'AIR', 'ROAD', 'RAIL'], true)) $mode = 'COURIER';
        $starts = trim((string) ($d['starts'] ?? '')); $rule = $starts !== '' ? '^' . preg_quote(strtoupper($starts), '/') . '\w*$' : null;
        $id = (int) DB::table('cat_tracking_carriers')->insertGetId(['fk_id_ent' => $ent, 'name' => mb_substr($name, 0, 80), 'mode' => $mode, 'url_pattern' => mb_substr($url, 0, 300), 'number_rule' => $rule,
            'aliases' => mb_substr(mb_strtolower($name), 0, 200), 'sort_no' => 0, 'f_active' => 1, 'f_system' => 0, 'created_by' => $user, 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('cat_tracking_carriers', $id, 'INSERT', ['carrier' => [null, $name]], null, $ent);
        return $id;
    }
}
