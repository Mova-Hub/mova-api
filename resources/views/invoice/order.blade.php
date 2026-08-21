<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Facture {{ $reference }} — Mova</title>
{{--
    A print-ready HTML document, not a PDF binary.

    No PDF library is involved on purpose. dompdf would add a composer
    dependency and a whole rendering engine whose CSS support is a decade
    behind, and the mobile app has no native package that can save a file —
    expo-print and expo-file-system are both absent from the build, and adding
    either means a new native build.

    Opened in the system browser, this is one tap from a real PDF on both
    platforms: Safari → Partager → Imprimer → pincer pour zoomer → Enregistrer
    dans Fichiers, Chrome → ⋮ → Imprimer → Enregistrer au format PDF. The @media
    print rules below are written so that output is A4-clean: no browser chrome,
    no page-break through a table row, colours preserved.

    Everything is inline — one file, no external stylesheet, no webfont. It has
    to render identically offline and inside a webview that may block requests.
--}}
<style>
    /* Fixed brand values, not CSS variables: some print engines drop custom
       properties, and an invoice that prints in black and white because of a
       var() fallback is a worse document. */
    :root { color-scheme: light; }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        padding: 24px;
        background: #F4F4F5;
        color: #0F172A;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 14px;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }

    .sheet {
        max-width: 780px;
        margin: 0 auto;
        background: #FFFFFF;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(15, 23, 42, .08), 0 12px 32px rgba(15, 23, 42, .06);
    }

    /* ── Header ── */
    .head {
        background: #064E3B;
        color: #FFFFFF;
        padding: 32px 36px;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 24px;
        flex-wrap: wrap;
    }
    .brand { display: flex; align-items: center; gap: 12px; }
    .mark {
        width: 40px; height: 40px; border-radius: 10px;
        background: #047857;
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 18px; letter-spacing: -.5px;
    }
    .brand-name { font-size: 20px; font-weight: 800; letter-spacing: 2px; }
    .brand-sub { font-size: 12px; opacity: .75; }
    .doc { text-align: right; }
    .doc-kind { font-size: 11px; letter-spacing: 1.5px; opacity: .75; text-transform: uppercase; }
    .doc-ref { font-size: 20px; font-weight: 700; }
    .doc-date { font-size: 12px; opacity: .75; }

    /* ── Status pill ── */
    .pill {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
        margin-top: 8px;
    }
    .pill-paid   { background: rgba(255,255,255,.18); color: #FFFFFF; }
    .pill-unpaid { background: #ED7615; color: #FFFFFF; }

    /* ── Body ── */
    .body { padding: 32px 36px; }

    .cols { display: flex; gap: 32px; flex-wrap: wrap; margin-bottom: 28px; }
    .col { flex: 1; min-width: 220px; }

    .label {
        font-size: 11px;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: #656F7C;
        margin-bottom: 6px;
    }
    .value { font-size: 14px; }
    .value strong { display: block; font-size: 15px; }

    /* ── Itinerary ── */
    .route { margin: 0 0 28px; padding: 0; list-style: none; }
    .route li {
        position: relative;
        padding: 0 0 16px 24px;
        font-size: 14px;
    }
    .route li:last-child { padding-bottom: 0; }
    .route li::before {
        content: '';
        position: absolute; left: 0; top: 6px;
        width: 9px; height: 9px; border-radius: 50%;
        border: 2px solid #047857; background: #FFFFFF;
    }
    .route li:last-child::before { background: #047857; border-radius: 2px; }
    /* The connecting line, drawn on the item rather than between items so it
       cannot desynchronise from the dots when a label wraps to two lines. */
    .route li:not(:last-child)::after {
        content: '';
        position: absolute; left: 4px; top: 17px; bottom: 2px;
        width: 1px; background: #E4E4E7;
    }
    .route small { color: #656F7C; }

    /* ── Table ── */
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    thead th {
        text-align: left;
        font-size: 11px;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: #656F7C;
        font-weight: 600;
        padding: 0 0 10px;
        border-bottom: 1px solid #E4E4E7;
    }
    tbody td { padding: 12px 0; border-bottom: 1px solid #F4F4F5; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }

    /* ── Totals ── */
    .totals { margin-left: auto; width: 280px; margin-top: 18px; }
    .totals div { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
    .totals .grand {
        border-top: 2px solid #0F172A;
        margin-top: 8px; padding-top: 12px;
        font-size: 20px; font-weight: 800;
        color: #047857;
    }

    .note {
        margin-top: 28px; padding: 14px 16px;
        background: #F4F4F5; border-radius: 10px;
        font-size: 12px; color: #576270;
    }

    .foot {
        padding: 20px 36px 28px;
        border-top: 1px solid #E4E4E7;
        font-size: 11px; color: #656F7C;
        display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    }

    /* ── Print button ── */
    .actions { max-width: 780px; margin: 0 auto 16px; text-align: right; }
    .print-btn {
        appearance: none; border: 0; cursor: pointer;
        background: #047857; color: #FFFFFF;
        font-size: 14px; font-weight: 600;
        padding: 11px 20px; border-radius: 10px;
        font-family: inherit;
    }

    @media print {
        /* Chrome and Safari strip backgrounds by default; an invoice whose
           header prints white is not the document that was designed. */
        body { background: #FFFFFF; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .sheet { box-shadow: none; border-radius: 0; max-width: none; }
        .actions { display: none; }
        tr, li { break-inside: avoid; page-break-inside: avoid; }
        @page { size: A4; margin: 14mm; }
    }
</style>
</head>
<body>

<div class="actions">
    <button class="print-btn" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
</div>

<div class="sheet">
    <div class="head">
        <div class="brand">
            <div class="mark">M</div>
            <div>
                <div class="brand-name">MOVA</div>
                <div class="brand-sub">Mova Mobility — Brazzaville, Congo</div>
            </div>
        </div>
        <div class="doc">
            <div class="doc-kind">{{ $isPaid ? 'Facture' : 'Facture proforma' }}</div>
            <div class="doc-ref">{{ $reference }}</div>
            <div class="doc-date">Émise le {{ $issuedAt }}</div>
            <span class="pill {{ $isPaid ? 'pill-paid' : 'pill-unpaid' }}">
                {{ $isPaid ? 'Payée' : 'En attente de paiement' }}
            </span>
        </div>
    </div>

    <div class="body">
        <div class="cols">
            <div class="col">
                <div class="label">Client</div>
                <div class="value">
                    <strong>{{ $order->contact_name }}</strong>
                    {{ $order->contact_phone }}
                </div>
            </div>
            <div class="col">
                <div class="label">Prestation</div>
                <div class="value">
                    <strong>{{ $eventLabel }}</strong>
                    {{ $dateLabel }}{{ $order->pickup_time ? ' · ' . $order->pickup_time : '' }}
                </div>
            </div>
            @if ($returnLabel)
                <div class="col">
                    <div class="label">Retour</div>
                    <div class="value">
                        <strong>{{ $returnLabel }}</strong>
                        {{ $order->return_time ?: '' }}
                    </div>
                </div>
            @endif
        </div>

        <div class="label">Itinéraire</div>
        <ul class="route">
            <li><strong>{{ $order->origin }}</strong><br><small>Départ</small></li>
            @foreach ($stops as $i => $stop)
                <li>{{ $stop }}<br><small>Arrêt {{ $i + 1 }}</small></li>
            @endforeach
            <li><strong>{{ $order->destination }}</strong><br><small>Destination</small></li>
        </ul>

        <table>
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th class="num">Qté</th>
                    <th class="num">Places</th>
                    <th class="num">Montant</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>
                            <strong>{{ $line['label'] }}</strong><br>
                            <small style="color:#656F7C">{{ $line['detail'] }}</small>
                        </td>
                        <td class="num">{{ $line['quantity'] }}</td>
                        <td class="num">{{ $line['seats'] }}</td>
                        <td class="num">{{ $line['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            @if ($distanceLabel)
                <div><span>Distance facturée</span><span>{{ $distanceLabel }}</span></div>
            @endif
            <div><span>Passagers</span><span>{{ $order->passengers ?? '—' }}</span></div>
            <div class="grand"><span>Total</span><span>{{ $totalLabel }}</span></div>
        </div>

        <div class="note">
            @if ($isPaid)
                Paiement reçu. Merci de votre confiance.
            @else
                Montant payable par Mobile Money (MTN MoMo, Airtel Money) depuis l’application Mova,
                ou directement auprès de notre équipe. Ce document ne vaut pas reçu tant que le
                paiement n’a pas été encaissé.
            @endif
        </div>
    </div>

    <div class="foot">
        <span>Mova Mobility · Brazzaville, République du Congo · reservation@mova-mobility.com</span>
        <span>{{ $reference }}</span>
    </div>
</div>

</body>
</html>
