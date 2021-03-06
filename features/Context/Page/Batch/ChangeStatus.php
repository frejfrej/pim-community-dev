<?php

namespace Context\Page\Batch;

use Context\Page\Base\Wizard;

/**
 * Batch ChangeStatus page
 *
 * @author    Gildas Quemener <gildas@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class ChangeStatus extends Wizard
{
    protected $elements = array(
        'Products status' => array('css' => '#pim_catalog_mass_edit_action_operation_toEnable')
    );

    /**
     * Enable the products
     * @return ChangeStatus
     */
    public function enableProducts()
    {
        $this->getElement('Products status')->check();

        return $this;
    }

    /**
     * Disable the products
     * @return ChangeStatus
     */
    public function disableProducts()
    {
        $this->getElement('Products status')->uncheck();

        return $this;
    }
}
