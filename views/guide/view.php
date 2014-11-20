<?php
/**
 * @var $this yii\web\View
 * @var $model app\models\GuideSection
 */
use app\apidoc\SideNavWidget;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = $model->getPageTitle();
?>
<nav class="navbar navbar-default guide-view" role="navigation">
    <div class="container">
        <form class="nav navbar-form navbar-right" role="search">
            <div class="form-group">
                <input type="text" class="form-control" placeholder="Search">
            </div>
        </form>
        <ul class="nav navbar-nav navbar-right">
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Version <?= $model->version ?> <span class="caret"></span></a>
                <ul class="dropdown-menu" role="menu">
                    <?php foreach ($model->getVersionOptions() as $version): ?>
                        <li role="presentation">
                            <?= Html::a($version, ['guide/view', 'version' => $version, 'language' => $model->language, 'section' => $model->name], [
                                'role' => 'menuitem',
                                'tabindex' => -1,
                            ]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false"><?= $model->getLanguageName() ?> <span class="caret"></span></a>
                <ul class="dropdown-menu" role="menu">
                    <?php foreach ($model->getLanguageOptions() as $lang => $name): ?>
                        <li role="presentation">
                            <?= Html::a($name, ['guide/view', 'version' => $model->version, 'language' => $lang, 'section' => $model->name], [
                                'role' => 'menuitem',
                                'tabindex' => -1,
                            ]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        </ul>
    </div>
</nav>

<div class="row">
    <div class="col-md-3">
        <?php
        $nav = [];
        foreach ($model->getGuideChapters() as $chapter) {
            $items = [];
            foreach($chapter['content'] as $chContent) {
                $items[] = [
                    'label' => $chContent['headline'],
                    'url' => Url::to(['guide/view', 'section' => $chContent['file'], 'language' => $model->language, 'version' => $model->version]),
                    'active' => $model->name === $chContent['file'],
                ];
            }
            $nav[] = [
                'label' => $chapter['headline'],
                'items' => $items,
            ];
        } ?>
        <?= SideNavWidget::widget([
            'id' => 'navigation',
            'items' => $nav,
            'view' => $this,
        ]) ?>
    </div>
    <div class="col-md-9 guide-content" role="main">
        <?= $model->getContent() ?>
    </div>
</div>