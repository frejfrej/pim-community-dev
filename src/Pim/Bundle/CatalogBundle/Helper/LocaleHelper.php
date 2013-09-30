<?php

namespace Pim\Bundle\CatalogBundle\Helper;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl;
use Pim\Bundle\CatalogBundle\Manager\LocaleManager;
use Symfony\Component\Security\Core\SecurityContextInterface;

/**
 * LocaleHelper essentially allow to translate locale code to localized locale label
 *
 * Static locales are not initialized on the constructor because
 * when LocaleHelper is constructed, the user is not yet initialized
 * and by the way don't have locale code
 *
 * @author    Romain Monceau <romain@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class LocaleHelper
{
    private $security;
    private $localeManager;
    private $defaultLocale;
    private $request;
    
    public function __construct(LocaleManager $localeManager, SecurityContextInterface $security, $defaultLocale)
    {
        $this->localeManager = $localeManager;
        $this->security = $security;
        $this->defaultLocale = $defaultLocale;
    }
    /**
     * Sets the current request
     * 
     * @param \Symfony\Component\HttpFoundation\Request $request
     */
    public function setRequest(Request $request = null)
    {
        $this->request = $request;
    }

    /**
     * Returns the current locale from the request, or the default locale if no active request is found
     * 
     * @return string
     */
    public function getCurrentLocale()
    {
        return $this->request ? $this->request->getLocale() : $this->defaultLocale;
    }

    /**
     * Initialized the locales list (if needed) and get the localized label
     *
     * @param string $code the code of the local's label
     * @param string $locale the locale in which the label should be translated
     * @return string
     */
    public function getLocaleLabel($code, $locale = null)
    {
        if (is_null($locale)) {
            $locale = $this->getCurrentLocale();
        }
        return \Locale::getDisplayName($code, $locale);
    }

    /**
     * Returns the symbol for a currency
     * 
     * @param string $currency
     */
    public function getCurrencySymbol($currency, $locale = null)
    {
        if (is_null($locale)) {
            $locale = $this->getCurrentLocale();
        }
        return Intl\Intl::getCurrencyBundle()->getCurrencySymbol($currency, $locale);
    }
    
    public function getLocaleCurrency()
    {
        $localeCode = $this->getCatalogLocale();
        $locale = $this->localeManager->getLocaleByCode($localeCode);

        return ($locale !== null and $locale->getDefaultCurrency()) ? $locale->getDefaultCurrency()->getCode() : null;
    }
    
    /**
     * @param string $code
     *
     * @return string
     */
    public function getFlag($code)
    {
        return sprintf(
            '<img src="%s" class="flag flag-%s" alt="%s" /><code class="flag-language">%s</code>',
            '/bundles/pimui/images/blank.gif',
            \Locale::getRegion($code),
            $this->getLocaleLabel($code),
            \Locale::getPrimaryLanguage($code)
        );
    }
    /**
     * @return string|NULL
     */
    private function getCatalogLocale()
    {
        if (null === $token = $this->securityContext->getToken()) {
            return null;
        }

        if (!is_object($user = $token->getUser())) {
            return null;
        }

        return (string) $user->cataloglocale;
    }
}
