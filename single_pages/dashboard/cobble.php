<?php defined('C5_EXECUTE') or die(_("Access Denied.")); ?>

<h1><span><?php echo $title ?></span></h1>
<div class="ccm-dashboard-inner">
    <form method="post" id="ccm-cobble-query" action="<?php echo $this->action('view') ?>">
        <table border="0" cellpadding="3">
            <tr>
                <td align="right"><?php echo t('Informational Queries:') ?></td>
                <td><?php echo $form->select('query_info', $queries_info,
                        array('onChange' => 'document.getElementById(\'query_diag\').selectedIndex = 0; this.form.submit();')) ?></td>
            </tr>
            <tr>
                <td align="right"><?php echo t('Diagnostic Queries:') ?></td>
                <td><?php echo $form->select('query_diag', $queries_diag,
                        array('onChange' => 'document.getElementById(\'query_info\').selectedIndex = 0; this.form.submit();')) ?></td>
            </tr>
        </table>
    </form>

    <?php if (empty($query_info) && empty($query_diag)) { ?>
        <p><?php echo t('Please choose a query from one of the lists above.'); ?></p>
    <?php } else { ?>
        <div style="max-width: 60em; margin-top: 10px;">
            Results for &nbsp;<strong><span style="font-size: larger;"><?php echo $queryName; ?>:</span></strong>&nbsp;&nbsp;
            <?php if (empty($results)) { ?>
                <span style="color: #2a2;"><?php echo t('No matching records were found'); ?></span>
            <?php } else { ?>
                <form style="display: inline; margin-left: 20px" method="post" id="ccm-cobble-query"
                      action="<?php echo $this->action('send_csv') ?>">
                    <?php echo $form->submit('export', 'Export Results as CSV') ?>
                    <?php echo $form->hidden('query_info', $query_info) ?>
                    <?php echo $form->hidden('query_diag', $query_diag) ?>
                </form>
            <?php } ?>
            <br/><br/>

            <?php echo $description ?>
        </div>
        <br/>

        <table border="0" cellspacing="1" cellpadding="0" class="grid-list">
            <tr>
                <?php foreach ($colHeads as $heading) { ?>
                    <td class="subheader"><?php echo $heading ?></td>
                <?php } ?>
            </tr>
            <?php foreach ($results as $row) { ?>
                <tr>
                    <?php foreach ($row as $col) { ?>
                        <td valign="top"><?php echo $col ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
        </table>
    <?php } ?>
    <br/>

</div>
