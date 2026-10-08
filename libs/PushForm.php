<?php

declare(strict_types=1);

/**
 * Formular der Push Zentrale: Nachrichtenliste mit eigenem Bearbeiten-Dialog je Zeile (Symcon >= 7.0),
 * Empfängerliste, globale Bedingung und Sperrfrist. Ausgelagert, damit module.php überschaubar bleibt.
 */
trait PushForm
{
    public function GetConfigurationForm(): string
    {
        $types = array_map(fn(string $t): array => ['caption' => $this->Translate('type:' . $t), 'value' => $t], PushOutputs::types());
        $modes = [];
        foreach (SpeechTrigger::modeCaptions() as $value => $caption) {
            $modes[] = ['caption' => $caption, 'value' => $value];
        }
        $sounds = array_map(fn(string $s): array => ['caption' => $s === '' ? $this->Translate('none') : $s, 'value' => $s], PushOutputs::SOUNDS);
        $names = $this->names();
        $messageForm = [
            ['type' => 'ValidationTextBox', 'name' => 'name', 'caption' => 'Name', 'width' => '100%', 'validate' => '\S'],
            ['type' => 'ExpansionPanel', 'caption' => 'Trigger', 'expanded' => true, 'items' => [
                ['type' => 'SelectCondition', 'name' => 'TriggerCondition', 'caption' => 'Rule', 'multi' => false],
                ['type' => 'Select', 'name' => 'TriggerMode', 'caption' => 'Trigger', 'options' => $modes, 'width' => '100%', 'value' => SpeechTrigger::MODE_BECOMES],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'DelaySeconds', 'caption' => 'Only after the rule holds for', 'suffix' => ' s', 'minimum' => 0, 'value' => 0],
                    ['type' => 'NumberSpinner', 'name' => 'RepeatMinutes', 'caption' => 'Repeat while it holds, every', 'suffix' => ' min', 'minimum' => 0, 'value' => 0],
                ]],
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Message', 'expanded' => true, 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'Title', 'caption' => 'Title (max. 32 characters)', 'width' => '100%'],
                ['type' => 'ValidationTextBox', 'name' => 'Texts', 'caption' => 'Text (one variant per line)', 'multiline' => true, 'width' => '100%'],
                ['type' => 'ValidationTextBox', 'caption' => 'Placeholders (select and copy)', 'value' => SpeechText::placeholderHelp(fn(string $t): string => $this->Translate($t)), 'multiline' => true, 'width' => '100%'],
                ['type' => 'SelectScript', 'name' => 'TextScript', 'caption' => 'or text from script (its output is the text)', 'value' => 0],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'SelectIcon', 'name' => 'Icon', 'caption' => 'Icon'],
                    ['type' => 'Select', 'name' => 'Sound', 'caption' => 'Sound', 'options' => $sounds, 'value' => ''],
                    ['type' => 'SelectObject', 'name' => 'TargetObject', 'caption' => 'Opens on tap', 'value' => 0],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => 'Preview text', 'onClick' => 'echo PUSHZ_PreviewMessage($id, $Title, $Texts, $TextScript, $TriggerCondition);'],
                    ['type' => 'Button', 'caption' => 'Send test to all', 'onClick' => 'echo PUSHZ_TestMessage($id, $Title, $Texts, $TextScript, $TriggerCondition, $Icon, $Sound, $TargetObject);'],
                ]],
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Condition', 'items' => [
                ['type' => 'SelectCondition', 'name' => 'Condition', 'multi' => true],
            ]],
        ];
        return (string)json_encode([
            'elements' => [
                ['type' => 'List', 'name' => 'Messages', 'caption' => 'Messages', 'add' => true, 'delete' => true, 'rowCount' => 12, 'changeOrder' => true,
                    'form' => $messageForm,
                    'columns' => [
                        ['caption' => 'Active', 'name' => 'active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Name', 'name' => 'name', 'width' => '220px', 'add' => '', 'save' => true],
                        ['caption' => 'Title', 'name' => 'Title', 'width' => '180px', 'add' => '', 'save' => true],
                        ['caption' => 'Text', 'name' => 'Texts', 'width' => 'auto', 'add' => '', 'save' => true],
                        ['caption' => 'ID', 'name' => 'msgId', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                    ]],
                ['type' => 'Label', 'caption' => 'Who gets which message is switched with the variables of this instance (one per message and recipient), also in the visualization.'],
                ['type' => 'ExpansionPanel', 'caption' => 'Recipients', 'items' => [
                    ['type' => 'List', 'name' => 'Recipients', 'caption' => 'Recipients', 'add' => true, 'delete' => true, 'rowCount' => 5,
                        'columns' => [
                            ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Type', 'name' => 'type', 'width' => '220px', 'add' => PushOutputs::VISU, 'edit' => ['type' => 'Select', 'options' => $types]],
                            ['caption' => 'Visualization', 'name' => 'instance', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'SelectInstance']],
                        ]],
                    ['type' => 'Label', 'caption' => 'A push always goes to every device of the chosen visualization. For single persons or devices create one visualization each and enable only those devices in its "Notifications" tab.'],
                    ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Select', 'name' => 'TestTarget', 'caption' => 'Recipient', 'options' => array_map(static fn(string $n): array => ['caption' => $n, 'value' => $n], $names ?: [''])],
                        ['type' => 'Button', 'caption' => 'Send test', 'onClick' => 'echo PUSHZ_TestRecipient($id, $TestTarget);'],
                    ]],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Global condition', 'items' => [
                    ['type' => 'SelectCondition', 'name' => 'Condition', 'multi' => true],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'Cooldown', 'caption' => 'Same notification at most every', 'suffix' => ' s', 'minimum' => 0],
            ],
            'actions' => [],
            'status' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
