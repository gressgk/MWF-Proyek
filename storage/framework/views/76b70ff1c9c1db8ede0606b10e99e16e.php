<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Transparansi - <?php echo e($campaign->judul); ?></title>

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

    <h2><?php echo e($campaign->judul); ?></h2>

    <div class="campaign-info">
        <strong>Recipient:</strong>
        <?php echo e($campaign->Penerima->name ?? '-'); ?>

        <br>

        <strong>Campaign Status:</strong>
        <?php echo e(ucfirst(str_replace('_', ' ', $campaign->status))); ?>

        <br>

        <strong>Report Generated:</strong>
        <?php echo e(now()->format('d M Y')); ?>

    </div>

    <h3>Transparency Reports</h3>

    <?php $__empty_1 = true; $__currentLoopData = $campaign->Reports; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $report): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>

        <div class="report">

            <h3><?php echo e($report->judul); ?></h3>

            <div class="report-date">
                Published:
                <?php echo e($report->created_at->format('d M Y')); ?>

            </div>

            <p><?php echo e($report->isi); ?></p>

            <p class="amount">
                Amount Spent:
                Rp<?php echo e(number_format($report->nominal_terpakai ?? 0, 0, ',', '.')); ?>

            </p>

            
            <?php
                $photos = $report->Photos->pluck('foto_path')->toArray();

                if ($report->foto_bukti) {
                    $photos[] = $report->foto_bukti;
                }
            ?>

            <?php if(count($photos) > 0): ?>
                <h3>Supporting Photos</h3>

                <?php $__currentLoopData = $photos; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $photo): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $photoPath = storage_path('app/public/' . $photo);
                    ?>

                    <?php if(file_exists($photoPath)): ?>
                        <img src="<?php echo e($photoPath); ?>" class="photo" alt="Supporting photo">
                    <?php endif; ?>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            <?php endif; ?>

            
            <?php if($report->dokumen_bukti): ?>
                <p>
                    <strong>Supporting Document:</strong>
                    <?php echo e(basename($report->dokumen_bukti)); ?>

                    <br>
                    <span class="subtitle">
                        The original supporting PDF is available through the online campaign page.
                    </span>
                </p>
            <?php endif; ?>

        </div>

    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>

        <p class="empty">No transparency reports have been published yet.</p>

    <?php endif; ?>

    <div class="footer">
        &copy; <?php echo e(date('Y')); ?> WeCare. Berbagi kebaikan, bersama.
        <br>
        This document was generated automatically by WeCare.
    </div>

</body>

</html>
<?php /**PATH C:\Users\Aspire\Downloads\KULIAH EVEE\SMT5\FAI\MWF-Proyek\resources\views/donatur/laporan-pdf.blade.php ENDPATH**/ ?>