<?php

namespace wbraganca\dynamicform\tests;

use PHPUnit\Framework\TestCase;
use wbraganca\dynamicform\DynamicFormWidget;

/**
 * Characterization tests for DynamicFormWidget's HTML handling (removeItems/run).
 * See docs/superpowers/plans/2026-10-26-php85-upgrade.md, Task 2.
 *
 * Baseline results recorded on PHP 8.5.12 (see commit body / kanban card t_344115ac).
 */
class DynamicFormWidgetTest extends TestCase
{
    /**
     * Minimal stub model: DynamicFormWidget::init() requires a yii\base\Model instance,
     * and run() reads isNewRecord (an ActiveRecord concept not present on base Model).
     */
    private function makeModel(bool $isNewRecord = true): \yii\base\Model
    {
        $model = new DynamicFormWidgetTestModel();
        $model->isNewRecord = $isNewRecord;

        return $model;
    }

    private function makeWidget(array $extra = []): DynamicFormWidget
    {
        return new DynamicFormWidget(array_merge([
            'widgetItem' => '.item',
            'widgetBody' => '.container-items',
            'widgetContainer' => 'dynamicform_wrapper',
            'formId' => 'test-form',
            'formFields' => ['name'],
            'model' => $this->makeModel(),
        ], $extra));
    }

    private function removeItems(string $html): string
    {
        $widget = $this->makeWidget();
        $method = new \ReflectionMethod($widget, 'removeItems');

        try {
            return $method->invoke($widget, $html);
        } finally {
            // init() -> initOptions() opens an output buffer for run() to later consume;
            // we bypass run(), so close it ourselves to avoid PHPUnit's "risky" warning.
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
    }

    public function testRemoveItemsKeepsNoItems(): void
    {
        $html = '<div class="container-items"><div class="item">a</div><div class="item">b</div></div>';

        $out = $this->removeItems($html);

        $this->assertStringNotContainsString('class="item"', $out);
    }

    public function testRemoveItemsReturnsFragmentWithoutDocumentWrapper(): void
    {
        $html = '<div class="container-items"><div class="item">a</div><div class="item">b</div></div>';

        $out = $this->removeItems($html);

        $this->assertStringNotContainsString('<head', $out);
        $this->assertStringNotContainsString('<body', $out);
    }

    public function testRemoveItemsPreservesUtf8Text(): void
    {
        $html = '<div class="container-items"><div class="item">x</div><p>héllo 中文</p></div>';

        $out = $this->removeItems($html);

        $this->assertStringContainsString('héllo 中文', $out);
    }

    public function testTemplatePreservesEntitiesAndBooleanAttributes(): void
    {
        $widget = $this->makeWidget(['min' => 1]);

        ob_start();
        echo '<div class="item"><p>a &amp; b</p><input type="checkbox" checked></div>';
        try {
            $widget->run();
        } catch (\Throwable $e) {
            // run() also registers view/asset-manager side effects unrelated to this
            // characterization (template extraction happens earlier, at lines 229-234).
        } finally {
            ob_end_clean();
        }

        $options = (new \ReflectionProperty($widget, '_options'))->getValue($widget);
        $template = $options['template'] ?? '';

        $this->assertStringContainsString('&amp;', $template);
        $this->assertStringContainsString('checked', $template);
    }

    public function testRunWithoutItemsDoesNotThrow(): void
    {
        $widget = $this->makeWidget(['min' => 0]);

        ob_start();
        echo '<div class="container-items"></div>';
        $widget->run();
        $output = ob_get_clean();

        $this->assertStringContainsString('dynamicform_wrapper', $output);
    }
}

/**
 * Named test double: DynamicFormWidget::run() calls Html::getInputId/getInputName,
 * which require formName() — unsupported on anonymous classes extending yii\base\Model.
 */
class DynamicFormWidgetTestModel extends \yii\base\Model
{
    public $isNewRecord = true;
    public $name;
}
