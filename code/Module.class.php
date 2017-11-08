<?php


namespace FormTools\Modules\FieldTypeTinymce;

use FormTools\Core;
use FormTools\FieldTypes;
use FormTools\Hooks;
use FormTools\Module as FormToolsModule;
use PDO, PDOException;


class Module extends FormToolsModule
{
    protected $moduleName = "TinyMCE Field";
    protected $moduleDesc = "This module lets you choose a TinyMCE rich-text editor for your form fields.";
    protected $author = "Ben Keen";
    protected $authorEmail = "ben.keen@gmail.com";
    protected $authorLink = "https://formtools.org";
    protected $version = "2.0.2";
    protected $date = "2017-11-07";
    protected $originLanguage = "en_us";

    protected $jsFiles = array(
        "{FTROOT}/global/scripts/sortable.js",
        "{MODULEROOT}/tinymce/tinymce.min.js"
    );

    protected $nav = array(
        "module_name" => array("index.php", false),
        "word_help"   => array("help.php", false)
    );


    /**
     * Our installation function. This adds the required data to the field types and field settings tables for
     * the field to become immediately usable.
     *
     * @param integer $module_id
     */
    public function install($module_id)
    {
        $db = Core::$db;
        $LANG = Core::$L;
        $L = $this->getLangStrings();

        // check it's not already installed
        $field_type_info = FieldTypes::getFieldTypeByIdentifier("tinymce");
        if (!empty($field_type_info)) {
            return array(false, $LANG["notify_module_already_installed"]);
        }

        // find the LAST field type group. Most installations won't have the Custom Fields module installed so
        // the last group will always be "Special Fields". For installations that DO, and that it's been customized,
        // the user can always move this new field type to whatever group they want. Plus, this module will be
        // installed by default, so it's almost totally moot
        $db->query("
            SELECT group_id
            FROM   {PREFIX}list_groups
            WHERE  group_type = 'field_types'
            ORDER BY list_order DESC
            LIMIT 1
        ");
        $db->execute();
        $group_id = $db->fetch(PDO::FETCH_COLUMN);

        try {
            $db->beginTransaction();

            // now find out how many field types there are in the group so we can add the row with the correct list order
            $db->query("SELECT count(*) as c FROM {PREFIX}field_types WHERE group_id = :group_id");
            $db->bind("group_id", $group_id);
            $db->execute();

            $next_list_order = $db->fetch(PDO::FETCH_COLUMN) + 1;

            $field_type_id = self::addFieldType($module_id, $group_id, $next_list_order);

            // the validation rule
            self::addValidation($field_type_id);

            // now insert the settings and their options
            self::addToolbarTypeSetting($field_type_id);
            self::addToolbarResizingSetting($field_type_id);
            self::addFieldCommentsSetting($field_type_id);

            self::resetHooks();

            $db->processTransaction();
            return array(true, "");
        } catch (PDOException $e) {
            $db->rollbackTransaction();
            print_r($e);
            return array(false, $L["notify_error_installing"] . $e->getMessage());
        }
    }


    /**
     * Uninstallation completely removes the field type. It also changes the field type ID from any WYSIWYG fields
     * to a generic textarea.
     *
     * @param integer $module_id
     */
    public function uninstall($module_id)
    {
        FieldTypes::deleteFieldType("tinymce", "textarea");
        return array(true, "");
    }


//    public function upgrade () {
//        // TODO: the field type settings changed with this upgrade, but we can keep the mapping
//    }

    public static function resetHooks ()
    {
        Hooks::unregisterModuleHooks("field_type_tinymce");
        Hooks::registerHook("template", "field_type_tinymce", "head_bottom", "", "includeFiles");
        Hooks::registerHook("template", "field_type_tinymce", "standalone_form_fields_head_bottom", "", "includeStandaloneFiles");
    }


    /**
     * This includes the tinyMCE file on the Edit Submission pages.
     */
    public function includeFiles($hook_name, $page_data)
    {
        $root_url = Core::getRootUrl();
        $curr_page = $page_data["page"];

        if ($curr_page != "admin_edit_submission" && $curr_page != "client_edit_submission") {
            return;
        }

        echo "<script src=\"$root_url/modules/field_type_tinymce/tinymce/tinymce.min.js\"></script>";
    }


    public function includeStandaloneFiles($hook_name, $page_data)
    {
        $root_url = Core::getRootUrl();
        echo "<script src=\"$root_url/modules/field_type_tinymce/tinymce/tinymce.min.js\"></script>";
    }


    /**
     * Updates the default settings for the WYSIWYG field.
     *
     * @param array $info
     */
    public function updateSettings($info)
    {
        $db = Core::$db;
        $L = $this->getLangStrings();

        // to update them we need to know the field type ID - use the identifier to get it
        $field_type_info = FieldTypes::getFieldTypeByIdentifier("tinymce");

        if (!isset($field_type_info["field_type_id"]) || !is_numeric($field_type_info["field_type_id"])) {
            return array(false, $L["notify_update_settings_no_field_found"]);
        }

        $field_type_id = $field_type_info["field_type_id"];

        // now update each of the settings. Klutzy!
        $identifiers = array("toolbar", "resizing");
        foreach ($identifiers as $identifier) {
            switch ($identifier) {
                case "resizing":
                    if (!isset($info[$identifier])) {
                        $new_default_value = "true";
                    } else {
                        $new_default_value = ($info[$identifier] == "yes") ? "true" : "";
                    }
                    break;
                case "path_info_location":
                    if (!isset($info[$identifier])) {
                        $new_default_value = "bottom";
                    } else {
                        $new_default_value = $info[$identifier];
                    }
                    break;
                default:
                    $new_default_value = $info[$identifier];
                    break;
            }

            $db->query("
                UPDATE {PREFIX}field_type_settings
                SET    default_value = :default_value
                WHERE  field_type_id = :field_type_id AND
                       field_setting_identifier = :identifier
                LIMIT 1
            ");
            $db->bindAll(array(
                "default_value" => $new_default_value,
                "field_type_id" => $field_type_id,
                "identifier" => $identifier
            ));
            $db->execute();
        }

        return array(true, $L["notify_default_settings_updated"]);
    }


    // helpers

    private static function addFieldType ($module_id, $group_id, $list_order)
    {
        $db = Core::$db;

        $view_field_smarty_markup =<<< END
    {if \$CONTEXTPAGE == "edit_submission"}
        {\$VALUE}
    {elseif \$CONTEXTPAGE == "submission_listing"}
        {\$VALUE|strip_tags}
    {else}
        {\$VALUE|nl2br}
    {/if}
END;

        $edit_field_smarty_markup =<<< END
    <textarea name="{\$NAME}" id="cf_{\$NAME}_id" class="cf_tinymce">{\$VALUE}</textarea>
    <script>
    cf_tinymce_settings["{\$NAME}"] = {literal}{{/literal}
        skin: "lightgray",
        branding: false,
        menubar: false,
        elementpath: false,
    {if \$toolbar == "basic"}
        toolbar: [
            'bold italic underline strikethrough | bullist numlist'
        ],
    {elseif \$toolbar == "simple"}
        toolbar: [
            'bold italic underline strikethrough | bullist numlist | outdent indent | blockquote hr | link unlink forecolor backcolor'
        ],
        plugins: 'hr link textcolor lists',
    {elseif \$toolbar == "advanced"}
        toolbar: [
            'bold italic underline strikethrough | bullist numlist | outdent indent | blockquote hr | undo redo link unlink | fontselect fontsizeselect',
            'forecolor backcolor | subscript superscript code'
        ],
        plugins: 'hr link textcolor lists',
    {elseif \$toolbar == "expert"}
        toolbar: [
            'bold italic underline strikethrough | bullist numlist | outdent indent | blockquote hr |  formatselect fontselect fontsizeselect',
            'undo redo link unlink | forecolor backcolor | subscript superscript | newdocument charmap removeformat cleanup code'
        ],
        plugins: 'hr link textcolor lists',
    {/if}
    {if \$resizing}
        statusbar: true,
        resize: true
    {else}
        statusbar: false,
        resize: false
    {/if}
    {literal}}{/literal}
    </script>
    {if \$comments}
        <div class="cf_field_comments">{\$comments}</div>
    {/if}
END;


        $resource_css =<<< END
body .mce-ico {
    font-size: 13px;
}
body .mce-btn button {
    padding: 3px 5px 3px 7px;
}
END;

        $resources_js =<<< END
    // this is populated by each tinyMCE WYWISYG with their settings on page load
    var cf_tinymce_settings = {};
    
    $(function() {
        $('textarea.cf_tinymce').each(function() {
            var field_name = $(this).attr("name");
            var settings   = cf_tinymce_settings[field_name];
            settings.selector = "#" + $(this).attr("id");
            tinymce.init(settings);
        });
    });

    cf_tinymce_settings.check_required = function() {
        var errors = [];
        for (var i=0; i<rsv_custom_func_errors.length; i++) {
            if (rsv_custom_func_errors[i].func != "cf_tinymce_settings.check_required") {
                continue;
            }
            var field_name = rsv_custom_func_errors[i].field;
            var val = $.trim(tinyMCE.get("cf_" + field_name + "_id").getContent());
            if (!val) {
                var el = document.edit_submission_form[field_name];
                errors.push([el, rsv_custom_func_errors[i].err]);
            }
        }
        if (errors.length) {
            return errors;
        }
        return true;
    }
END;

        $db->query("
            INSERT INTO {PREFIX}field_types (is_editable, non_editable_info, managed_by_module_id, field_type_name,
                field_type_identifier, group_id, is_file_field, is_date_field, raw_field_type_map, 
                raw_field_type_map_multi_select_id, list_order, compatible_field_sizes,
                view_field_rendering_type, view_field_php_function_source, view_field_php_function,
                view_field_smarty_markup, edit_field_smarty_markup, php_processing, resources_css, resources_js)
            VALUES (:is_editable, :non_editable_info, :module_id, :field_type_name, :field_type_identifier, :group_id,
                :is_file_field, :is_date_field, :raw_field_type_map, :raw_field_type_map_multi_select_id, :list_order,
                :compatible_field_sizes, :view_field_rendering_type, :view_field_php_function_source, :view_field_php_function,
                :view_field_smarty_markup, :edit_field_smarty_markup, :php_processing, :resources_css, :resources_js)
        ");
        $db->bindAll(array(
            "is_editable" => "no",
            "non_editable_info" => "This module may only be edited via the tinyMCE module.",
            "module_id" => $module_id,
            "field_type_name" => "{\$LANG.word_wysiwyg}",
            "field_type_identifier" => "tinymce",
            "group_id" => $group_id,
            "is_file_field" => "no",
            "is_date_field" => "no",
            "raw_field_type_map" => "textarea",
            "raw_field_type_map_multi_select_id" => null,
            "list_order" => $list_order,
            "compatible_field_sizes" => "large,very_large",
            "view_field_rendering_type" => "smarty",
            "view_field_php_function_source" => "core",
            "view_field_php_function" => "",
            "view_field_smarty_markup" => $view_field_smarty_markup,
            "edit_field_smarty_markup" => $edit_field_smarty_markup,
            "php_processing" => "",
            "resources_css" => $resource_css,
            "resources_js" => $resources_js
        ));
        $db->execute();

        return $db->getInsertId();
    }

    private static function addValidation ($field_type_id)
    {
        $db = Core::$db;

        $db->query("
            INSERT INTO {PREFIX}field_type_validation_rules (field_type_id, rsv_rule, rule_label, rsv_field_name,
              custom_function, custom_function_required, default_error_message, list_order)
            VALUES (:field_type_id, :rsv_rule, :rule_label, :rsv_field_name, :custom_function, :custom_function_required,
              :default_error_message, :list_order)
        ");
        $db->bindAll(array(
            "field_type_id" => $field_type_id,
            "rsv_rule" => "function",
            "rule_label" => "{\$LANG.word_required}",
            "rsv_field_name" => "",
            "custom_function" => "cf_tinymce_settings.check_required",
            "custom_function_required" => "yes",
            "default_error_message" => "{\$LANG.validation_default_rule_required}",
            "list_order" => 1
        ));
        $db->execute();
    }

    private static function addToolbarTypeSetting ($field_type_id)
    {
        $db = Core::$db;

        $db->query("
            INSERT INTO {PREFIX}field_type_settings (field_type_id, field_label, field_setting_identifier, field_type,
              field_orientation, default_value, list_order)
            VALUES (:field_type_id, 'Toolbar', 'toolbar', 'select', 'na', 'simple', 1)
        ");
        $db->bind("field_type_id", $field_type_id);
        $db->execute();

        $setting_id = $db->getInsertId();

        $db->query("
            INSERT INTO {PREFIX}field_type_setting_options (setting_id, option_text, option_value, option_order, is_new_sort_group)
            VALUES
                (:setting_id1, 'Basic', 'basic', 1, 'yes'),
                (:setting_id2, 'Simple', 'simple', 2, 'yes'),
                (:setting_id3, 'Advanced', 'advanced', 3, 'yes'),
                (:setting_id4, 'Expert', 'expert', 4, 'yes')
        ");
        $db->bindAll(array(
            "setting_id1" => $setting_id,
            "setting_id2" => $setting_id,
            "setting_id3" => $setting_id,
            "setting_id4" => $setting_id
        ));
        $db->execute();
    }

    private static function addToolbarResizingSetting ($field_type_id)
    {
        $db = Core::$db;

        $db->query("
            INSERT INTO {PREFIX}field_type_settings (field_type_id, field_label, field_setting_identifier, field_type,
              field_orientation, default_value, list_order)
            VALUES (:field_type_id, 'Allow Toolbar Resizing', 'resizing', 'radios', 'horizontal', 'true', 6)
        ");
        $db->bind("field_type_id", $field_type_id);
        $db->execute();

        $setting_id = $db->getInsertId();

        $db->query("
            INSERT INTO {PREFIX}field_type_setting_options (setting_id, option_text, option_value, option_order, is_new_sort_group)
            VALUES (:setting_id1, 'Yes', 'true', 1, 'yes'),
                   (:setting_id2, 'No', 'false', 2, 'no')
        ");
        $db->bind("setting_id1", $setting_id);
        $db->bind("setting_id2", $setting_id);
        $db->execute();
    }

    private static function addFieldCommentsSetting ($field_type_id)
    {
        $db = Core::$db;

        $db->query("
            INSERT INTO {PREFIX}field_type_settings (field_type_id, field_label, field_setting_identifier, field_type,
              field_orientation, default_value, list_order)
            VALUES (:field_type_id, 'Field Comments', 'comments', 'textarea', 'na', '', 7)
        ");
        $db->bind("field_type_id", $field_type_id);
        $db->execute();
    }
}
