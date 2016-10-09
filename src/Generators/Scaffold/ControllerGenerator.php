<?php

namespace InfyOm\Generator\Generators\Scaffold;

use InfyOm\Generator\Common\CommandData;
use InfyOm\Generator\Generators\BaseGenerator;
use InfyOm\Generator\Utils\FileUtil;

class ControllerGenerator extends BaseGenerator
{
    /** @var CommandData */
    private $commandData;

    /** @var string */
    private $path;

    /** @var string */
    private $templateType;

    /** @var string */
    private $fileName;

    public function __construct(CommandData $commandData)
    {
        $this->commandData = $commandData;
        $this->path = $commandData->config->pathController;
        $this->templateType = config('infyom.laravel_generator.templates', 'core-templates');
        $this->fileName = $this->commandData->modelName.'Controller.php';
    }

    public function generate()
    {
        if ($this->commandData->getAddOn('datatables')) {
            $templateData = get_template('scaffold.controller.datatable_controller', 'laravel-generator');

            $this->generateDataTable();
        } else {
            $templateData = get_template('scaffold.controller.controller', 'laravel-generator');

            $paginate = $this->commandData->getOption('paginate');

            if ($paginate) {
                $templateData = str_replace('$RENDER_TYPE$', 'paginate('.$paginate.')', $templateData);
            } else {
                $templateData = str_replace('$RENDER_TYPE$', 'all()', $templateData);
            }
        }

        $templateData = fill_template($this->commandData->dynamicVars, $templateData);

        FileUtil::createFile($this->path, $this->fileName, $templateData);

        $this->commandData->commandComment("\nController created: ");
        $this->commandData->commandInfo($this->fileName);
    }

    private function generateDataTable()
    {
        $templateData = get_template('scaffold.datatable', 'laravel-generator');

        $templateData = fill_template($this->commandData->dynamicVars, $templateData);

        $headerFieldTemplate = get_template('scaffold.views.datatable_column', $this->templateType);

        $headerFields = [];

        foreach ($this->commandData->fields as $field) {
            if (!$field->inIndex) {
                continue;
            }
            $headerFields[] = $fieldTemplate = fill_template_with_field_data(
                $this->commandData->dynamicVars,
                $this->commandData->fieldNamesMapping,
                $headerFieldTemplate,
                $field
            );
        }

        $foreignColumns = [];
        foreach ($this->commandData->fields as $column) {
            if (preg_match("/(.*)_id/", $column->name, $match) && $column->inIndex) {
                $fieldJoinName = end($match);
                $foreignColumns[$column->name] = $fieldJoinName;
                foreach ($headerFields as &$hF) {
                    $hF = str_replace(
                        "'$column->name' => ['name' => '$column->name', 'data' => '$column->name']",
                        "'$fieldJoinName' => ['name' => '{$fieldJoinName}_name', 'data' => '{$fieldJoinName}_name']",
                        $hF
                    );
                }
            }
        }

        $foreignKeys = \DB::table("INFORMATION_SCHEMA.KEY_COLUMN_USAGE")
            ->where("TABLE_SCHEMA", env("DB_DATABASE"))
            ->where("TABLE_NAME", $this->commandData->dynamicVars['$MODEL_NAME_PLURAL_SNAKE$'])
            ->whereNotNull("REFERENCED_TABLE_NAME")
            ->select(["COLUMN_NAME", "REFERENCED_TABLE_NAME", "REFERENCED_COLUMN_NAME"])->get();
        $foreignKeys = json_decode(json_encode($foreignKeys), true);

        $datatableJoinSelect = '->select("' . $this->commandData->dynamicVars['$MODEL_NAME_PLURAL_SNAKE$'] . '.*"';
        $selectNames = [];
        $datatableJoins = [];
        foreach ($foreignKeys as $foreign) {
            if (isset($foreignColumns[$foreign["COLUMN_NAME"]])) {
                $selectNames[] = '"' . $foreign["REFERENCED_TABLE_NAME"] . ".name as " . $foreignColumns[$foreign["COLUMN_NAME"]] . '_name"';
                $datatableJoins[] = '->leftJoin("' . $foreign["REFERENCED_TABLE_NAME"] . '", "' . $this->commandData->dynamicVars['$MODEL_NAME_PLURAL_SNAKE$'] . '.' . $foreign["COLUMN_NAME"] . '", "=", "' . $foreign["REFERENCED_TABLE_NAME"] . '.id")';
            }
        }
        $datatableJoinSelect .= ((count($selectNames)) ? ', ' . implode(", ", $selectNames) : "") . ")";

        $templateData = str_replace(
            '$DATATABLE_JOIN_SELECT$',
            "$" . $this->commandData->dynamicVars['$MODEL_NAME_PLURAL_CAMEL$'] . " = " .
            "$" . $this->commandData->dynamicVars['$MODEL_NAME_PLURAL_CAMEL$'] . $datatableJoinSelect . ";",
            $templateData
        );

        if (count($datatableJoins)) {
            $datatableJoins = implode("\n\t\t\t", $datatableJoins);
            $templateData = str_replace(
                '$DATATABLE_JOINS$',
                "$" . $this->commandData->dynamicVars['$MODEL_NAME_PLURAL_CAMEL$'] . " = " .
                "$" . $this->commandData->dynamicVars['$MODEL_NAME_PLURAL_CAMEL$'] . $datatableJoins . ";",
                $templateData
            );
        } else {
            $templateData = str_replace('$DATATABLE_JOINS$', "", $templateData);
        }

        $path = $this->commandData->config->pathDataTables;

        $fileName = $this->commandData->modelName.'DataTable.php';

        $fields = implode(','.infy_nl_tab(1, 3), $headerFields);

        $templateData = str_replace('$DATATABLE_COLUMNS$', $fields, $templateData);

        FileUtil::createFile($path, $fileName, $templateData);

        $this->commandData->commandComment("\nDataTable created: ");
        $this->commandData->commandInfo($fileName);
    }

    public function rollback()
    {
        if ($this->rollbackFile($this->path, $this->fileName)) {
            $this->commandData->commandComment('Controller file deleted: '.$this->fileName);
        }

        if ($this->commandData->getAddOn('datatables')) {
            if ($this->rollbackFile($this->commandData->config->pathDataTables, $this->commandData->modelName.'DataTable.php')) {
                $this->commandData->commandComment('DataTable file deleted: '.$this->fileName);
            }
        }
    }
}
