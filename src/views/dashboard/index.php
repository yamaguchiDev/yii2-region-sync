<?php

use yii\helpers\Html;

/** @var string $tab */
/** @var array|null $diff */
/** @var string|null $error */
$this->title = 'Синхронизация регионального сайта';
$tabs = [
    'availability' => 'Синхронизация наличия',
    'prices' => 'Синхронизация цен',
    'products' => 'Импорт товаров',
];
?>
<h1><?= Html::encode($this->title) ?></h1>
<ul class="nav nav-tabs" role="tablist">
    <?php foreach ($tabs as $key => $title): ?>
        <li class="<?= $tab === $key ? 'active' : '' ?>">
            <?= Html::a(Html::encode($title), ['/regionsync/dashboard/index', 'tab' => $key]) ?>
        </li>
    <?php endforeach; ?>
</ul>
<div class="panel panel-default"><div class="panel-body">
    <?php foreach (['success', 'error'] as $level): ?>
        <?php if (Yii::$app->session->hasFlash($level)): ?>
            <div class="alert alert-<?= $level === 'error' ? 'danger' : 'success' ?>">
                <?= Html::encode(Yii::$app->session->getFlash($level)) ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($tab === 'availability'): ?>
        <h2>Синхронизация наличия</h2>
        <p>Существующий расчёт и синхронизация остатков по текущему региону.</p>
        <?= Html::beginForm(['/regionsync/dashboard/sync-availability'], 'post') ?>
            <label for="region-sync-city">geoCityId</label>
            <?= Html::textInput('geoCityId', Yii::$app->getModule('regionsync')->geoCityId, [
                'id' => 'region-sync-city', 'class' => 'form-control', 'required' => true,
                'pattern' => '[1-9][0-9]*', 'style' => 'max-width:220px',
            ]) ?>
            <?= Html::submitButton('Синхронизировать наличие', ['class' => 'btn btn-primary', 'style' => 'margin-top:10px']) ?>
        <?= Html::endForm() ?>
        <p>Консоль: <code>php yii availability/sync &lt;geoCityId&gt;</code>; подробности: <code>php yii help availability/sync</code>.</p>
    <?php elseif ($tab === 'prices'): ?>
        <h2>Синхронизация цен</h2>
        <p>Существующий импорт цен с основного сайта. Запускается отдельно от импорта товаров.</p>
        <?= Html::beginForm(['/regionsync/dashboard/sync-prices'], 'post') ?>
            <?= Html::submitButton('Синхронизировать цены', ['class' => 'btn btn-primary']) ?>
        <?= Html::endForm() ?>
        <p>Консоль: <code>php yii regionsync/price/sync</code>; подробности: <code>php yii help regionsync/price/sync</code>.</p>
    <?php else: ?>
        <h2>Импорт товаров</h2>
        <p>Первый релиз: категории и атрибуты не переносятся. Категории назначаются вручную после импорта.
            Технические параметры (tech_params) импортируются.</p>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= Html::encode($error) ?></div>
        <?php elseif ($diff !== null): ?>
            <p>Основных опубликованных вариантов у донора: <?= (int)$diff['sourceCount'] ?>;
                основных вариантов здесь: <?= (int)$diff['localCount'] ?>;
                отсутствует: <?= count($diff['missing']) ?>;
                конфликтов itemId: <?= count($diff['conflicts']) ?>;
                только здесь: <?= count($diff['localOnly']) ?>.</p>
            <?php if ($diff['conflicts']): ?>
                <div class="alert alert-warning">Повторяющиеся itemId не импортируются автоматически:
                    <?= Html::encode(implode(', ', array_keys($diff['conflicts']))) ?>.</div>
            <?php endif; ?>
            <table class="table table-striped table-condensed">
                <thead><tr><th>itemId</th><th>goods.id донора</th><th>Товар</th><th>URI</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($diff['missing'] as $item): ?>
                        <tr>
                            <td><?= (int)$item['itemId'] ?></td>
                            <td><?= (int)$item['productId'] ?></td>
                            <td><?= Html::encode($item['name']) ?></td>
                            <td><?= Html::encode($item['uri']) ?></td>
                            <td><?= Html::beginForm(['/regionsync/dashboard/import-one'], 'post') ?>
                                <?= Html::hiddenInput('itemId', $item['itemId']) ?>
                                <?= Html::submitButton('Импортировать', ['class' => 'btn btn-primary btn-sm']) ?>
                                <?= Html::endForm() ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div></div>
