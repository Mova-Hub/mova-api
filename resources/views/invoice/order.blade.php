<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Facture {{ $reference }}</title>
{{--
    Rendered by dompdf, which is NOT a browser.

    Everything here is tables and blocks on purpose: dompdf supports neither
    flexbox nor grid, silently collapsing both to stacked blocks. A template
    written with `display:flex` looks right in a browser preview and prints as a
    single ragged column — so the layout is built the way dompdf actually lays
    out, not the way modern CSS would.

    Other constraints this file works within:
      · `font-family: DejaVu Sans` — the only bundled face with full Latin-1
        coverage. Helvetica drops é, è, à and ç, which is unusable in French.
      · No external assets. The logo is drawn with a coloured block and text,
        so the PDF has no network dependency and renders identically offline.
      · Colours are literal hex. Custom properties are not resolved.
--}}
<style>
    @page { margin: 0; }

    body {
        margin: 0;
        padding: 0;
        font-family: "DejaVu Sans", sans-serif;
        font-size: 10.5pt;
        line-height: 1.45;
        color: #0F172A;
    }

    /* ── Header band ──
       WHITE, not the dark green it used to be.

       The Mova logo is green-and-orange on transparent. Placed on #064E3B the
       green wordmark all but disappears and the orange fights the ground —
       there is no dark variant of the mark, so the band changed instead. The
       brand colour now arrives as the rule beneath it, which reads as
       deliberate rather than as a logo dropped on the wrong background. */
    .head {
        background-color: #FFFFFF;
        color: #0F172A;
        padding: 26pt 34pt 18pt;
        border-bottom: 3pt solid #4CAF50;
    }
    .head td { vertical-align: top; }

    /* Height-constrained, width auto: the mark is ~2.4:1, and fixing both
       dimensions in dompdf stretches rather than fits. */
    .logo { height: 30pt; }

    .brand-name { font-size: 16pt; font-weight: bold; letter-spacing: 3pt; color: #4CAF50; }
    .brand-sub  { font-size: 8pt; color: #64748B; margin-top: 3pt; }

    .doc-kind { font-size: 8pt; letter-spacing: 1.4pt; color: #64748B; text-transform: uppercase; }
    .doc-ref  { font-size: 15pt; font-weight: bold; color: #0F172A; }
    .doc-date { font-size: 8.5pt; color: #64748B; }

    .pill {
        display: inline-block;
        padding: 3pt 10pt;
        border-radius: 20pt;
        font-size: 8.5pt;
        font-weight: bold;
        margin-top: 5pt;
    }
    .pill-paid   { background-color: #047857; color: #FFFFFF; }
    .pill-unpaid { background-color: #ED7615; color: #FFFFFF; }

    /* ── Body ── */
    .body { padding: 24pt 34pt 0; }

    .label {
        font-size: 7.5pt;
        letter-spacing: 1pt;
        text-transform: uppercase;
        color: #656F7C;
        padding-bottom: 3pt;
    }
    .party td { vertical-align: top; padding-bottom: 16pt; }
    .party strong { font-size: 11pt; }

    /* ── Itinerary ──
       A table, so the marker column and the label column stay aligned when a
       long address wraps. Absolute-positioned pseudo-elements — the browser
       approach — are unreliable in dompdf. */
    .route { width: 100%; border-collapse: collapse; margin-bottom: 18pt; }
    .route td { padding: 3pt 0; vertical-align: top; }
    .route .dot { width: 16pt; }
    .marker {
        display: inline-block;
        width: 7pt; height: 7pt;
        border-radius: 7pt;
        border: 1.6pt solid #047857;
        background-color: #FFFFFF;
    }
    .marker-end { background-color: #047857; border-radius: 1.5pt; }
    .route small { color: #656F7C; font-size: 8pt; }

    /* ── Line items ── */
    table.items { width: 100%; border-collapse: collapse; }
    table.items thead th {
        text-align: left;
        font-size: 7.5pt;
        letter-spacing: 1pt;
        text-transform: uppercase;
        color: #656F7C;
        padding-bottom: 7pt;
        border-bottom: 0.8pt solid #E4E4E7;
    }
    table.items tbody td {
        padding: 9pt 0;
        border-bottom: 0.8pt solid #F1F1F2;
        vertical-align: top;
    }
    .num { text-align: right; }
    .muted { color: #656F7C; font-size: 8.5pt; }

    /* ── Totals ──
       Right-aligned via an outer table with an empty spacer cell: `margin-left:
       auto` does not work on a table in dompdf. */
    .totals { width: 100%; border-collapse: collapse; margin-top: 14pt; }
    .totals .spacer { width: 58%; }
    .totals td { padding: 3pt 0; }
    .totals .grand td {
        border-top: 1.6pt solid #0F172A;
        padding-top: 8pt;
        font-size: 14pt;
        font-weight: bold;
        color: #047857;
    }

    .note {
        margin-top: 20pt;
        padding: 10pt 12pt;
        background-color: #F4F4F5;
        border-radius: 5pt;
        font-size: 8.5pt;
        color: #576270;
    }

    /* Pinned to the bottom of every page — dompdf's `fixed` positioning is
       page-repeating, which is exactly what a document footer wants. */
    .foot {
        position: fixed;
        bottom: 16pt; left: 34pt; right: 34pt;
        border-top: 0.8pt solid #E4E4E7;
        padding-top: 7pt;
        font-size: 7.5pt;
        color: #656F7C;
    }
</style>
</head>
<body>

<div class="head">
    <table width="100%">
        <tr>
            <td width="55%">
                {{--
                    The real logo, embedded as a base64 data URI by
                    DocumentBranding. dompdf will not fetch a URL with
                    isRemoteEnabled off, and silently drops the image with it
                    on when the host is slow — which is precisely when an
                    invoice is generated from a queue worker.

                    Falls back to the wordmark: a missing file should produce a
                    plain invoice, never a failed download at the moment a
                    client asked for one.
                --}}
                @if (!empty($branding['logo']))
                    <img src="{{ $branding['logo'] }}" class="logo" alt="Mova">
                @else
                    <div class="brand-name">MOVA</div>
                @endif
                <div class="brand-sub">{{ $branding['legalName'] }} — {{ $branding['address'] }}</div>
            </td>
            <td align="right">
                <div class="doc-kind">{{ $isPaid ? 'Facture' : 'Facture proforma' }}</div>
                <div class="doc-ref">{{ $reference }}</div>
                <div class="doc-date">Émise le {{ $issuedAt }}</div>
                <span class="pill {{ $isPaid ? 'pill-paid' : 'pill-unpaid' }}">
                    {{ $isPaid ? 'PAYÉE' : 'EN ATTENTE DE PAIEMENT' }}
                </span>
            </td>
        </tr>
    </table>
</div>

<div class="body">
    <table width="100%" class="party">
        <tr>
            <td width="34%">
                <div class="label">Client</div>
                <strong>{{ $order->contact_name }}</strong><br>
                {{ $order->contact_phone }}
            </td>
            <td width="33%">
                <div class="label">Prestation</div>
                <strong>{{ $eventLabel }}</strong><br>
                {{ $dateLabel }}{{ $order->pickup_time ? ' · ' . $order->pickup_time : '' }}
            </td>
            <td width="33%">
                <div class="label">{{ $returnLabel ? 'Retour' : 'Passagers' }}</div>
                @if ($returnLabel)
                    <strong>{{ $returnLabel }}</strong><br>
                    {{ $order->return_time ?: 'Heure à confirmer' }}
                @else
                    <strong>{{ $order->passengers ?? '—' }}</strong><br>
                    <span class="muted">Aller simple</span>
                @endif
            </td>
        </tr>
    </table>

    <div class="label">Itinéraire</div>
    <table class="route">
        <tr>
            <td class="dot"><span class="marker"></span></td>
            <td><strong>{{ $order->origin }}</strong><br><small>Départ</small></td>
        </tr>
        @foreach ($stops as $i => $stop)
            <tr>
                <td class="dot"><span class="marker"></span></td>
                <td>{{ $stop }}<br><small>Arrêt {{ $i + 1 }}</small></td>
            </tr>
        @endforeach
        <tr>
            <td class="dot"><span class="marker marker-end"></span></td>
            <td><strong>{{ $order->destination }}</strong><br><small>Destination</small></td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th width="52%">Désignation</th>
                <th class="num" width="12%">Qté</th>
                <th class="num" width="14%">Places</th>
                <th class="num" width="22%">Montant</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>
                        <strong>{{ $line['label'] }}</strong><br>
                        <span class="muted">{{ $line['detail'] }}</span>
                    </td>
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td class="num">{{ $line['seats'] }}</td>
                    <td class="num">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="spacer"></td>
            <td></td>
        </tr>
        @if ($distanceLabel)
            <tr>
                <td class="spacer"></td>
                <td><table width="100%"><tr><td>Distance facturée</td><td class="num">{{ $distanceLabel }}</td></tr></table></td>
            </tr>
        @endif
        <tr>
            <td class="spacer"></td>
            <td><table width="100%"><tr><td>Passagers</td><td class="num">{{ $order->passengers ?? '—' }}</td></tr></table></td>
        </tr>
        <tr class="grand">
            <td class="spacer"></td>
            <td><table width="100%"><tr><td>Total</td><td class="num">{{ $totalLabel }}</td></tr></table></td>
        </tr>
    </table>

    <div class="note">
        @if ($isPaid)
            Paiement reçu. Merci de votre confiance.
        @else
            Montant payable par Mobile Money (MTN MoMo, Airtel Money) depuis l’application Mova, ou
            directement auprès de notre équipe. Ce document ne vaut pas reçu tant que le paiement
            n’a pas été encaissé.
        @endif
    </div>
</div>

<div class="foot">
    Mova Mobility · Brazzaville, République du Congo · reservation@mova-mobility.com · {{ $reference }}
</div>

</body>
</html>
