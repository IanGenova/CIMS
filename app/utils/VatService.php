<?php

namespace App\utils;

use Illuminate\Support\Facades\DB;

/**
 * Philippine VAT classification and the statutory Senior Citizen / PWD discount.
 *
 * Two things live here because they are inseparable in law. The discount is not
 * "20% off" -- it is a VAT exemption *and* a 20% discount, applied in that order,
 * and applying them the other way round (or applying 20% to a VAT-inclusive
 * price) overcharges the customer and understates the exemption in the books.
 *
 *   RA 9994 s.4  Senior citizens      RA 10754  Persons with disability
 *   RR 7-2010    Implementing rules   RMC 38-2012  Clarifications
 *
 * The order the law requires, per line:
 *
 *   1. Strip the VAT, giving the VAT-exempt amount.
 *   2. Take 20% of that amount as the discount.
 *   3. The beneficiary's share carries no output VAT at all.
 *
 * A line that is already VAT-exempt -- a prescription medicine, say -- has no VAT
 * to strip, so step 1 is a no-op and the 20% applies to the price as it stands.
 * Getting that wrong discounts the medicine twice.
 *
 * Shared bills are apportioned by head. If one senior eats with three others,
 * the discount applies to a quarter of the table and the rest still pays VAT
 * (RMC 38-2012). That is what beneficiaries/totalPersons expresses.
 */
class VatService
{
    const VATABLE = 'vatable';
    const EXEMPT = 'vat_exempt';
    const ZERO_RATED = 'zero_rated';

    /** The statutory rate. Not configurable per sale -- it is the law. */
    const STATUTORY_RATE = 0.20;

    private static $settings = null;

    public static function forget()
    {
        self::$settings = null;
    }

    public static function classes()
    {
        return [self::VATABLE, self::EXEMPT, self::ZERO_RATED];
    }

    /** The output VAT rate, as a percentage. */
    public static function rate()
    {
        $s = self::settings();

        return $s && $s->vat_rate !== null ? (float) $s->vat_rate : 12.0;
    }

    /** Whether quoted prices already contain VAT. Philippine retail: yes. */
    public static function pricesIncludeVat()
    {
        $s = self::settings();

        return $s ? (bool) $s->prices_include_vat : true;
    }

    /**
     * Split one line into its VAT-exempt base and its VAT.
     *
     * @param  float   $amount  the line total as the customer sees it
     * @param  string  $class   vatable | vat_exempt | zero_rated
     * @return array{net: float, vat: float}
     */
    public static function split($amount, $class = self::VATABLE)
    {
        $amount = (float) $amount;

        // Only a vatable line carries VAT. An exempt or zero-rated price contains
        // none, whichever way prices are quoted, so it is already its own net.
        if ($class !== self::VATABLE) {
            return ['net' => $amount, 'vat' => 0.0];
        }

        $rate = self::rate() / 100;

        if (self::pricesIncludeVat()) {
            $net = $rate == -1 ? $amount : $amount / (1 + $rate);

            return ['net' => $net, 'vat' => $amount - $net];
        }

        return ['net' => $amount, 'vat' => $amount * $rate];
    }

    /**
     * The whole computation for a document.
     *
     * $lines each: ['amount' => float, 'vat_class' => string, 'sc_pwd_eligible' => bool]
     *   amount is the line total the customer would pay before any statutory
     *   discount -- VAT-inclusive when prices are quoted that way.
     *
     * $statutory (optional):
     *   ['type' => 'senior'|'pwd', 'beneficiaries' => int, 'total_persons' => int]
     *
     * Returns the per-line breakdown and the summary an invoice must print.
     */
    public static function compute(array $lines, array $statutory = null)
    {
        $type = $statutory['type'] ?? 'none';
        $applies = in_array($type, ['senior', 'pwd'], true);

        $beneficiaries = max(0, (int) ($statutory['beneficiaries'] ?? 0));
        $totalPersons = max(1, (int) ($statutory['total_persons'] ?? 1));

        // The beneficiaries' share of the bill. Capped at the whole of it, so a
        // miskeyed headcount cannot discount more than was sold.
        $share = $applies ? min(1.0, $beneficiaries / $totalPersons) : 0.0;
        if ($applies && $beneficiaries === 0) {
            $share = 0.0;
        }

        $out = [];
        $sum = ['vatable_sales' => 0.0, 'vat_amount' => 0.0, 'vat_exempt_sales' => 0.0,
            'zero_rated_sales' => 0.0, 'statutory_discount' => 0.0, 'total_due' => 0.0];

        foreach ($lines as $i => $line) {
            $amount = (float) ($line['amount'] ?? 0);
            $class = $line['vat_class'] ?? self::VATABLE;
            if (! in_array($class, self::classes(), true)) {
                $class = self::VATABLE;
            }
            $eligible = array_key_exists('sc_pwd_eligible', $line)
                ? (bool) $line['sc_pwd_eligible'] : true;

            $split = self::split($amount, $class);
            $net = $split['net'];
            $vat = $split['vat'];

            // How much of this line belongs to a beneficiary.
            $lineShare = ($share > 0 && $eligible) ? $share : 0.0;

            // Step 1 and 3: the beneficiary's portion is exempt, so it keeps its
            // net and loses its VAT. Everyone else's portion is untouched.
            $exemptBase = $net * $lineShare;
            $vatCharged = $vat * (1 - $lineShare);

            // Step 2: 20% of the VAT-exempt amount, never of the gross.
            $discount = round($exemptBase * self::STATUTORY_RATE, 2);

            // Rounding is done in one deliberate order, because on a split line
            // the parts do not round to the same centavo as the whole.
            //
            // The amount the customer actually pays is rounded from the exact
            // figures, so it is never a centavo off what is owed. The printed
            // buckets are then made to reconcile to it: everything but the
            // vatable figure is rounded on its own, and vatable takes up the
            // remainder. That way the arithmetic on the face of the invoice --
            //   vatable + exempt + zero-rated + VAT - discount = total
            // -- holds exactly, which is what an examiner adds up.
            $netR = round($net, 2);
            $vatR = round($vatCharged, 2);
            $lineTotal = round($net + $vatCharged - $discount, 2);

            $vatable = 0.0;
            $exempt = 0.0;
            $zero = 0.0;

            if ($class === self::ZERO_RATED) {
                $zero = $netR;
            } elseif ($class === self::EXEMPT) {
                $exempt = $netR;
            } else {
                // A vatable line bought partly by a senior is split between the
                // two buckets -- that split is what the VAT return is built from.
                $exempt = round($exemptBase, 2);
            }

            // The balancing figure.
            $vatable = round($lineTotal - $exempt - $zero - $vatR + $discount, 2);

            $out[] = [
                'index' => $i,
                'vat_class' => $class,
                'sc_pwd_eligible' => $eligible,
                'amount' => round($amount, 2),
                'net' => $netR,
                'vat' => $vatR,
                'vatable_sales' => $vatable,
                'vat_exempt_sales' => $exempt,
                'zero_rated_sales' => $zero,
                'statutory_discount' => $discount,
                'order_discount_share' => (float) ($line['order_discount_share'] ?? 0),
                'total' => $lineTotal,
            ];

            // Totals are sums of the rounded lines, so the invoice adds up to
            // what is printed on it rather than to a more precise number nobody
            // can see.
            $sum['vatable_sales'] += $vatable;
            $sum['vat_amount'] += $vatR;
            $sum['vat_exempt_sales'] += $exempt;
            $sum['zero_rated_sales'] += $zero;
            $sum['statutory_discount'] += $discount;
            $sum['total_due'] += $lineTotal;
        }

        foreach ($sum as $k => $v) {
            $sum[$k] = round($v, 2);
        }

        $sum['statutory_type'] = $applies && $share > 0 ? $type : 'none';
        $sum['statutory_beneficiaries'] = $applies ? $beneficiaries : 0;
        $sum['statutory_total_persons'] = $applies ? $totalPersons : 1;
        $sum['vat_rate'] = self::rate();
        $sum['prices_include_vat'] = self::pricesIncludeVat();

        return ['lines' => $out, 'summary' => $sum];
    }

    /**
     * The statutory details a request carries, or null when the sale is ordinary.
     * Absent or unrecognised input means no discount -- never a partial one.
     */
    public static function statutoryFromRequest($request)
    {
        $type = $request["statutory_type"] ?? null;

        if (! in_array($type, ["senior", "pwd"], true)) {
            return null;
        }

        // RA 9994 / RA 10754: the discount is granted on the presentation of an
        // ID, and the receipt must carry its number and the holder's name. It
        // was given with both blank (DATATEST K16).
        $missing = [];
        if (trim((string) ($request["statutory_id_number"] ?? "")) === "") {
            $missing["statutory_id_number"] = ["Enter the " . ($type === "senior" ? "Senior Citizen" : "PWD") . " ID number."];
        }
        if (trim((string) ($request["statutory_holder_name"] ?? "")) === "") {
            $missing["statutory_holder_name"] = ["Enter the name on the ID."];
        }
        if ($missing) {
            throw \Illuminate\Validation\ValidationException::withMessages($missing);
        }

        return [
            "type" => $type,
            "beneficiaries" => (int) ($request["statutory_beneficiaries"] ?? 1),
            "total_persons" => (int) ($request["statutory_total_persons"] ?? 1),
            "id_number" => $request["statutory_id_number"] ?? null,
            "holder_name" => $request["statutory_holder_name"] ?? null,
        ];
    }

    /**
     * Run the computation for a whole document, taking each line's classification
     * from its product and the line amount from the totals already calculated by
     * OrderTotals -- so the commercial arithmetic stays in one place and this
     * only classifies what came out of it.
     *
     * Shipping is charged VAT like anything else, so it joins as a vatable line;
     * it is not a purchase by the beneficiary, so it earns no statutory discount.
     *
     * Note: an order-level discount is not distributed across the VAT buckets.
     * Per-line discounts are, because they are already inside the line subtotal.
     */
    public static function forDocument(
        array $details, array $lineTotals, $shipping = 0, array $statutory = null, $orderDiscount = 0
    ) {
        // RA 9994: a senior (or PWD) gets the statutory discount or the shop's
        // promotional discount, whichever is higher -- not both (DATATEST K74,
        // owner default). Work out both and keep the one that costs the
        // customer less; summary.order_discount_applied says which promo stood.
        if ($statutory && (float) $orderDiscount > 0) {
            $withStatutory = self::forDocument($details, $lineTotals, $shipping, $statutory, 0);
            $withPromo = self::forDocument($details, $lineTotals, $shipping, null, $orderDiscount);

            return $withStatutory["summary"]["total_due"] <= $withPromo["summary"]["total_due"]
                ? $withStatutory : $withPromo;
        }

        $lines = [];
        $amounts = [];
        $subtotal = 0.0;

        foreach ($details as $i => $d) {
            $amount = (float) ($lineTotals[$i]["subtotal"] ?? 0);
            $amounts[$i] = $amount;
            $subtotal += $amount;
        }

        // A discount granted at the point of sale reduces the consideration, and
        // therefore the taxable base -- it cannot just come off the bottom of the
        // invoice and leave the VAT untouched. It is spread across the lines in
        // proportion to their value, so each line's VAT falls with it and the
        // printed buckets still add up. Shipping is excluded: the discount is on
        // the goods, not on the delivery.
        $orderDiscount = max(0.0, min((float) $orderDiscount, $subtotal));
        $spread = self::apportion($amounts, $orderDiscount);

        foreach ($details as $i => $d) {
            $class = self::forProduct($d["product_id"] ?? null);

            $lines[] = [
                "amount" => round($amounts[$i] - ($spread[$i] ?? 0), 2),
                "vat_class" => $class["vat_class"],
                "sc_pwd_eligible" => $class["sc_pwd_eligible"],
                "order_discount_share" => round($spread[$i] ?? 0, 2),
            ];
        }

        if ((float) $shipping > 0) {
            $lines[] = [
                "amount" => (float) $shipping,
                "vat_class" => self::VATABLE,
                "sc_pwd_eligible" => false,
            ];
        }

        $result = self::compute($lines, $statutory);
        // The promo that actually stands on this document (zero when the
        // statutory discount won; see the higher-of rule above).
        $result["summary"]["order_discount_applied"] = round($orderDiscount, 2);

        // Hand back only the product lines, so the caller can zip them against
        // its own details array without the shipping row shifting the indexes.
        $result["shipping_line"] = (float) $shipping > 0 ? array_pop($result["lines"]) : null;

        if ($statutory) {
            $result["summary"]["statutory_id_number"] = $statutory["id_number"] ?? null;
            $result["summary"]["statutory_holder_name"] = $statutory["holder_name"] ?? null;
        }

        return $result;
    }

    /**
     * Split an amount across lines in proportion to their value.
     *
     * The parts are rounded to the centavo and the last non-zero line takes the
     * residue, so the pieces add up to exactly what was handed in -- a discount
     * of 100 must remove 100, not 99.99.
     *
     * @return array<int,float> keyed the same way as $amounts
     */
    private static function apportion(array $amounts, $total)
    {
        $out = [];
        foreach ($amounts as $k => $v) {
            $out[$k] = 0.0;
        }

        $total = round((float) $total, 2);
        if ($total <= 0) {
            return $out;
        }

        $sum = array_sum($amounts);
        if ($sum <= 0) {
            return $out;
        }

        $running = 0.0;
        $lastKey = null;
        foreach ($amounts as $k => $v) {
            if ($v > 0) {
                $lastKey = $k;
            }
        }

        foreach ($amounts as $k => $v) {
            if ($v <= 0 || $k === $lastKey) {
                continue;
            }
            $part = round($total * ($v / $sum), 2);
            $out[$k] = $part;
            $running += $part;
        }

        if ($lastKey !== null) {
            $out[$lastKey] = round($total - $running, 2);
        }

        return $out;
    }

    /**
     * Classification for a product, falling back to vatable for anything not
     * explicitly marked -- the same answer the system gave before these columns
     * existed.
     *
     * @return array{vat_class: string, sc_pwd_eligible: bool}
     */
    public static function forProduct($productId)
    {
        $p = DB::table('products')->where('id', $productId)->first(['vat_class', 'sc_pwd_eligible']);

        if (! $p) {
            return ['vat_class' => self::VATABLE, 'sc_pwd_eligible' => true];
        }

        $class = $p->vat_class ?: self::VATABLE;

        return [
            'vat_class' => in_array($class, self::classes(), true) ? $class : self::VATABLE,
            'sc_pwd_eligible' => (bool) $p->sc_pwd_eligible,
        ];
    }

    private static function settings()
    {
        if (self::$settings === null) {
            self::$settings = DB::table('settings')->whereNull('deleted_at')->first() ?: false;
        }

        return self::$settings ?: null;
    }
}
