{ft_include file='modules_header.tpl'}

    <table cellpadding="0" cellspacing="0">
    <tr>
        <td width="45"><img src="images/tinymce.png" width="34" height="34" /></td>
        <td class="title">
            <a href="../../admin/modules">{$LANG.word_modules}</a>
            <span class="joiner">&raquo;</span>
            {$L.module_name}
        </td>
    </tr>
    </table>

    {ft_include file="messages.tpl"}

    <div class="margin_bottom_large">
        Use the fields below to configure the default settings for the TinyMCE field type.
    </div>

    <form action="{$same_page}" method="post">

    </form>

{ft_include file='modules_footer.tpl'}
