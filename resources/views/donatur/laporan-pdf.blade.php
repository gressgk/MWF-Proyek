<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Transparansi - {{ $campaign->judul }}</title>

    <style>
        @page {
            margin: 35px 40px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #333;
            font-size: 11px;
            line-height: 1.6;
        }

        .header {
            border-bottom: 3px solid #64876b;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .brand {
            font-size: 24px;
            font-weight: bold;
            color: #64876b;
        }

        .subtitle {
            color: #777;
            font-size: 10px;
        }

        h2 {
            font-size: 18px;
            margin-bottom: 5px;
            color: #333;
        }

        h3 {
            font-size: 13px;
            color: #64876b;
            margin-bottom: 5px;
        }

        .campaign-info {
            background: #f4f7f4;
            padding: 12px;
            margin-bottom: 20px;
        }

        .report {
            border-bottom: 1px solid #ddd;
            padding: 15px 0;
        }

        .report-date {
            color: #777;
            font-size: 10px;
        }

        .amount {
            font-weight: bold;
            color: #64876b;
        }

        .photo {
            width: 150px;
            height: 110px;
            object-fit: cover;
            margin: 5px 5px 5px 0;
            border: 1px solid #ddd;
        }

        .footer {
            border-top: 1px solid #ddd;
            margin-top: 30px;
            padding-top: 10px;
            text-align: center;
            color: #888;
            font-size: 9px;
        }

        .empty {
            color: #777;
            font-style: italic;
        }
    </style>
</head>

<body>

    <div class="header">
        <div class="brand">WeCare</div>
        <div class="subtitle">Transparency & Accountability Report</div>
    </div>

    <h2>{{ $campaign->judul }}</h2>

    <div class="campaign-info">
        <strong>Recipient:</strong>
        {{ $campaign->Penerima->name ?? '-' }}
        <br>

        <strong>Campaign Status:</strong>
        {{ ucfirst(str_replace('_', ' ', $campaign->status)) }}
        <br>

        <strong>Report Generated:</strong>
        {{ now()->format('d M Y') }}
    </div>

    <h3>Transparency Reports</h3>

    @forelse ($campaign->Reports as $report)

        <div class="report">

            <h3>{{ $report->judul }}</h3>

            <div class="report-date">
                Published:
                {{ $report->created_at->format('d M Y') }}
            </div>

            <p>{{ $report->isi }}</p>

            <p class="amount">
                Amount Spent:
                Rp{{ number_format($report->nominal_terpakai ?? 0, 0, ',', '.') }}
            </p>

            {{-- Supporting Photos --}}
            @php
                $photos = $report->Photos->pluck('foto_path')->toArray();

                if ($report->foto_bukti) {
                    $photos[] = $report->foto_bukti;
                }
            @endphp

            @if (count($photos) > 0)
                <h3>Supporting Photos</h3>

                @foreach ($photos as $photo)
                    @php
                        $photoPath = storage_path('app/public/' . $photo);
                    @endphp

                    @if (file_exists($photoPath))
                        <img src="{{ $photoPath }}" class="photo" alt="Supporting photo">
                    @endif
                @endforeach
            @endif

            {{-- Supporting PDF Document --}}
            @if ($report->dokumen_bukti)
                <p>
                    <strong>Supporting Document:</strong>
                    {{ basename($report->dokumen_bukti) }}
                    <br>
                    <span class="subtitle">
                        The original supporting PDF is available through the online campaign page.
                    </span>
                </p>
            @endif

        </div>

    @empty

        <p class="empty">No transparency reports have been published yet.</p>

    @endforelse

    <div class="footer">
        &copy; {{ date('Y') }} WeCare. Berbagi kebaikan, bersama.
        <br>
        This document was generated automatically by WeCare.
    </div>

</body>

</html>
