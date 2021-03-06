<?php

namespace Context;

use Symfony\Component\HttpFoundation\File\File;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Util\Inflector;
use Oro\Bundle\BatchBundle\Entity\JobInstance;
use Oro\Bundle\DataAuditBundle\Entity\Audit;
use Oro\Bundle\UserBundle\Entity\Role;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\UserBundle\Entity\UserApi;
use Pim\Bundle\CatalogBundle\Entity\Association;
use Pim\Bundle\CatalogBundle\Entity\AttributeGroup;
use Pim\Bundle\CatalogBundle\Entity\AttributeOption;
use Pim\Bundle\CatalogBundle\Entity\AttributeRequirement;
use Pim\Bundle\CatalogBundle\Entity\GroupType;
use Pim\Bundle\CatalogBundle\Entity\Category;
use Pim\Bundle\CatalogBundle\Entity\Channel;
use Pim\Bundle\CatalogBundle\Entity\Family;
use Pim\Bundle\CatalogBundle\Entity\FamilyTranslation;
use Pim\Bundle\CatalogBundle\Entity\Locale;
use Pim\Bundle\CatalogBundle\Entity\ProductAttribute;
use Pim\Bundle\CatalogBundle\Entity\ProductPrice;
use Pim\Bundle\CatalogBundle\Entity\Group;
use Behat\Gherkin\Node\TableNode;
use Behat\MinkExtension\Context\RawMinkContext;
use Behat\Gherkin\Node\PyStringNode;
use Oro\Bundle\FlexibleEntityBundle\Entity\Media;

/**
 * A context for creating entities
 *
 * @author    Gildas Quemener <gildas@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class FixturesContext extends RawMinkContext
{
    private $locales = array(
        'english' => 'en_US',
        'french'  => 'fr_FR',
        'german'  => 'de_DE',
    );

    private $channels = array(
        'ecommerce' => array('en_US', 'fr_FR'),
        'mobile'    => array('fr_FR'),
    );

    private $attributeTypes = array(
        'text'        => 'pim_catalog_text',
        'number'      => 'pim_catalog_number',
        'textarea'    => 'pim_catalog_textarea',
        'identifier'  => 'pim_catalog_identifier',
        'metric'      => 'pim_catalog_metric',
        'prices'      => 'pim_catalog_price_collection',
        'image'       => 'pim_catalog_image',
        'file'        => 'pim_catalog_file',
        'multiselect' => 'pim_catalog_multiselect',
        'simpleselect' => 'pim_catalog_simpleselect',
    );

    private $placeholderValues = array();

    /**
     * @BeforeScenario
     */
    public function resetPlaceholderValues()
    {
        $this->placeholderValues = array();
    }

    /**
     * @BeforeScenario
     */
    public function resetCurrentLocale()
    {
        foreach ($this->locales as $locale) {
            $this->createLocale($locale);
        }
    }

    /**
     * @BeforeScenario
     */
    public function resetChannels()
    {
        $tree = $this->createTree('default');
        foreach ($this->channels as $code => $locales) {
            $this->createChannel($code, ucfirst($code), $locales, $tree);
        }

        $this->flush();
    }

    /**
     * @BeforeScenario
     */
    public function createRequiredAttribute()
    {
        $this->createAttribute('SKU', false, 'identifier', true);
    }

    /**
     * @BeforeScenario
     */
    public function resetGroupTypes()
    {
        $types = array(
            'VARIANT' => 1,
            'X_SELL'  => 0,
        );
        foreach ($types as $code => $isVariant) {
            $this->createGroupType($code, $isVariant);
        }
    }

    /**
     * @BeforeScenario
     */
    public function clearUOW()
    {
        $this->getEntityManager()->clear();
    }

    /**
     * @BeforeScenario
     */
    public function clearPimFilesystem()
    {
        $fs = $this->getPimFilesystem();
        foreach ($fs->keys() as $key) {
            $fs->delete($key);
        }
    }

    /**
     * @param string         $sku
     * @param TableNode|null $translations
     *
     * @return Product
     * @Given /^the "([^"]*)" product(?: has the following translations:)?$/
     */
    public function theProductWithTheFollowingTranslations($sku, TableNode $translations = null)
    {
        $attributes = array();
        $product    = $this->getProduct($sku);

        if ($translations) {
            foreach ($translations->getHash() as $translation) {
                if (isset($attributes[$translation['attribute']])) {
                    $attribute = $attributes[$translation['attribute']];
                } else {
                    $attribute = $this->createAttribute($translation['attribute'], true);
                    $attributes[$translation['attribute']] = $attribute;
                }

                $value = $this->createValue(
                    $attribute,
                    $translation['value'],
                    $this->getLocaleCode($translation['locale'])
                );
                $product->addValue($value);
            }
        }

        $this->getProductManager()->save($product);

        return $product;
    }

    /**
     * @param string $sku
     *
     * @return \Pim\Bundle\CatalogBundle\Entity\Product
     *
     * @Given /^a "([^"]*)" product$/
     */
    public function aProduct($sku)
    {
        $product   = $this->theProductWithTheFollowingTranslations($sku);
        $this->getProductManager()->save($product);

        return $product;
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following products?:$/
     */
    public function theFollowingProduct(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $data = array_merge(array('family' => null), $data);

            $product = $this->aProduct($data['sku']);
            if ($data['family']) {
                $product->setFamily($this->getFamily($data['family']));
            }

            if (isset($data['enabled'])) {
                $product->setEnabled($data['enabled'] === 'yes');
            }

            $this->getProductManager()->save($product);
        }
    }

    /**
     * @param string $sku
     *
     * @Given /^an enabled "([^"]*)" product$/
     */
    public function anEnabledProduct($sku)
    {
        $this->aProduct($sku)->setEnabled(true);
    }

    /**
     * @param string $sku
     *
     * @Given /^a disabled "([^"]*)" product$/
     */
    public function aDisabledProduct($sku)
    {
        $this->aProduct($sku)->setEnabled(false);
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following families:$/
     * @Given /^the following family:$/
     */
    public function theFollowingFamilies(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $family = new Family();
            $family->setCode($data['code']);
            if (isset($data['label'])) {
                $family->setLocale('en_US')->setLabel($data['label']); // TODO translation refactoring
            }
            $this->persist($family);

            $translation = $this->createFamilyTranslation($family, $data['code']);
            $family->addTranslation($translation);
        }

        $this->flush();
    }

    /**
     * @param string    $family
     * @param TableNode $table
     *
     * @Given /^the family "([^"]*)" has the following attribute:$/
     */
    public function theFamilyHasTheFollowingAttribute($family, TableNode $table)
    {
        $family = $this->getFamily($family);

        foreach ($table->getHash() as $data) {
            $code = $this->camelize($data['label']);
            $attribute = $this->getAttribute($code);
            $family->addAttribute($attribute);
            if ('yes' === $data['attribute as label']) {
                $family->setAttributeAsLabel($attribute);
            }
        }

        $this->flush();
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following currencies:$/
     */
    public function theFollowingCurrencies(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $currency = new \Pim\Bundle\CatalogBundle\Entity\Currency;
            $currency->setCode($data['code']);
            $currency->setActivated($data['activated'] === 'yes');

            $this->persist($currency);
        }
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following locales:$/
     */
    public function theFollowingLocales(TableNode $table)
    {
        $em = $this->getEntityManager();
        foreach ($table->getHash() as $data) {
            $locale = $em->getRepository('PimCatalogBundle:Locale')->findOneBy(array('code' => $data['code']));
            if (!$locale) {
                $locale = new Locale();
                $locale->setCode($data['code']);
            }

            $locale->setFallback($data['fallback']);
            if ($data['activated'] === 'yes') {
                $locale->activate();
            }

            $em->persist($locale);
        }
        $em->flush();
    }

    /**
     * @Given /^there is no channel$/
     */
    public function thereIsNoChannel()
    {
        $em = $this->getEntityManager();
        $channels = $em->getRepository('PimCatalogBundle:Channel')->findAll();

        foreach ($channels as $channel) {
            $this->remove($channel);
        }
        $this->flush();
    }

    /**
     * @Given /^there is no product group$/
     */
    public function thereIsNoProductGroup()
    {
        $em = $this->getEntityManager();
        $groups = $em->getRepository('PimCatalogBundle:Group')->findAll();

        foreach ($groups as $group) {
            $this->remove($group);
        }
        $this->flush();
    }

    /**
     * @Given /^there is no attribute$/
     */
    public function thereIsNoAttribute()
    {
        $em = $this->getEntityManager();
        $attributes = $em->getRepository('PimCatalogBundle:ProductAttribute')->findAll();

        foreach ($attributes as $attribute) {
            $this->remove($attribute);
        }
        $this->flush();
    }

    /**
     * @param string $product
     * @param string $family
     *
     * @Given /^the product "([^"]*)" belongs to the family "([^"]*)"$/
     */
    public function theProductBelongsToTheFamily($product, $family)
    {
        $product = $this->getProduct($product);
        $family  = $this->getFamily($family);

        $product->setFamily($family);
        $this->flush();
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following attribute groups?:$/
     */
    public function theFollowingAttributeGroups(TableNode $table)
    {
        foreach ($table->getHash() as $index => $data) {
            $data = array_merge(
                array(
                    'locale' => 'english'
                ),
                $data
            );

            $group = $this->getAttributeGroup($data['code']);

            if (!$group) {
                $group = new AttributeGroup();
                $group->setSortOrder($index);
                $group->setCode($data['code']);
            }

            $group
                ->setLocale($this->getLocaleCode($data['locale']))
                ->setLabel($data['label']);


            $this->persist($group);
        }
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following product attributes?:$/
     */
    public function theFollowingProductAttributes(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $data = array_merge(
                array(
                    'position'     => 0,
                    'group'        => null,
                    'product'      => null,
                    'family'       => null,
                    'required'     => 'no',
                    'type'         => 'text',
                    'scopable'     => false,
                    'scopable'     => 'no',
                    'translatable' => 'no',
                ),
                $data
            );

            try {
                $code = $this->camelize($data['label']);
                $attribute = $this->getAttribute($code);
            } catch (\InvalidArgumentException $e) {
                $attribute = $this->createAttribute($data['label'], false, $data['type']);
            }

            $attribute->setSortOrder($data['position']);
            $attribute->setGroup($this->getAttributeGroup($data['group']));
            $attribute->setRequired(strtolower($data['required']) === 'yes');
            $attribute->setScopable(strtolower($data['scopable']) === 'yes');
            $attribute->setTranslatable(strtolower($data['translatable']) === 'yes');
            $attribute->setUseableAsGridColumn(true);
            $attribute->setUseableAsGridFilter(true);

            if ($family = $data['family']) {
                $family = $this->getFamily($family);
                $family->addAttribute($attribute);
            }

            if ($data['type'] === 'metric') {
                if (!empty($data['metric family']) && !empty($data['default metric unit'])) {
                    $attribute->setMetricFamily($data['metric family']);
                    $attribute->setDefaultMetricUnit($data['default metric unit']);
                } else {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Expecting metric family and default metric unit to be defined for attribute "%s"',
                            $data['label']
                        )
                    );
                }
            }

            if (!empty($data['product'])) {
                $product = $this->getProduct($data['product']);
                $value   = $this->createValue($attribute);

                $product->addValue($value);
                $this->getProductManager()->save($product);
            }
        }

        $this->flush();
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following product values?:$/
     */
    public function theFollowingProductValue(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $data = array_merge(array('scope' => null, 'locale' => null), $data);
            $data['locale']= (empty($data['locale'])) ? null : $data['locale'];
            $data['scope']= (empty($data['scope'])) ? null : $data['scope'];

            $product = $this->getProduct($data['product']);
            $value   = $product->getValue($this->camelize($data['attribute']), $data['locale'], $data['scope']);

            if ($value) {
                if ($data['scope']) {
                    $value->setScope($data['scope']);
                }
                if ($data['locale']) {
                    $value->setLocale($data['locale']);
                }
                if ($data['value']) {
                    if ($value->getAttribute()->getAttributeType() === $this->attributeTypes['prices']) {
                        foreach ($value->getPrices() as $price) {
                            $value->removePrice($price);
                        }
                        $prices = $this->createPricesFromString($data['value']);
                        foreach ($prices as $price) {
                            $value->addPrice($price);
                        }
                    } elseif ($value->getAttribute()->getAttributeType() === $this->attributeTypes['simpleselect']) {
                        $options = $value->getAttribute()->getOptions();
                        $optionValue = null;
                        foreach ($options as $option) {
                            if ($option->getCode() === $data['value']) {
                                $optionValue = $option;
                            }
                        }

                        if ($optionValue === null) {
                            throw new \InvalidArgumentException(sprintf('Unknown option value "%s"', $data['value']));
                        }

                        $value->setData($optionValue);
                    } else {
                        $value->setData($data['value']);
                    }
                }
            } else {
                $code = $this->camelize($data['attribute']);
                $attribute = $this->getAttribute($code);
                $value = $this->createValue($attribute, $data['value'], $data['locale'], $data['scope']);
                $product->addValue($value);
            }
        }

        $this->flush();
    }

    /**
     * @param string $attribute
     * @param string $family
     *
     * @Given /^the attribute "([^"]*)" has been removed from the "([^"]*)" family$/
     */
    public function theAttributeHasBeenRemovedFromTheFamily($attribute, $family)
    {
        $code      = $this->camelize($attribute);
        $attribute = $this->getAttribute($code);
        $family    = $this->getFamily($family);

        $family->removeAttribute($attribute);

        $this->flush();
    }

    /**
     * @param string $attribute
     * @param string $family
     *
     * @Given /^the attribute "([^"]*)" has been chosen as the family "([^"]*)" label$/
     */
    public function theAttributeHasBeenChosenAsTheFamilyLabel($attribute, $family)
    {
        $code      = $this->camelize($attribute);
        $attribute = $this->getAttribute($code);
        $family    = $this->getFamily($family);

        $family->setAttributeAsLabel($attribute);

        $this->flush();
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following categor(?:y|ies):$/
     */
    public function theFollowingCategories(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $category = new Category();
            $category->setCode($data['code']);
            $category->setLocale('en_US')->setLabel($data['label']); // TODO translation refactoring

            if (!empty($data['parent'])) {
                $parent = $this->getCategoryOrCreate($data['parent']);
                $category->setParent($parent);
            }

            if (isset($data['products'])) {
                $skus = explode(',', $data['products']);
                foreach ($skus as $sku) {
                    $category->addProduct($this->getProduct(trim($sku)));
                }
            }

            $this->persist($category);
        }
    }

    /**
     * @param TableNode $table
     *
     * @Then /^there should be the following categories:$/
     */
    public function thereShouldBeTheFollowingCategories(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $category = $this->getCategory($data['code']);

            assertEquals($data['label'], $category->getTranslation('en_US')->getLabel());
            if (empty($data['parent'])) {
                assertNull($category->getParent());
            } else {
                assertEquals($data['parent'], $category->getParent()->getCode());
            }
        }
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following channels?:$/
     */
    public function theFollowingChannels(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $data = array_merge(
                array(
                    'label'      => null,
                    'locales'    => null,
                    'currencies' => null,
                    'category'   => null,
                ),
                $data
            );

            $category = null;
            if ($data['category']) {
                $category = $this->getCategory($data['category']);
            }

            try {
                $channel = $this->getChannel($data['code']);

                if ($data['label']) {
                    $channel->setLabel($data['label']);
                }

                if ($category !== null) {
                    $channel->setCategory($category);
                }

                if ($data['locales']) {
                    foreach ($this->listToArray($data['locales']) as $localeCode) {
                        $channel->addLocale($this->getLocale($localeCode));
                    }
                }

                if ($data['currencies']) {
                    foreach ($this->listToArray($data['currencies']) as $currencyCode) {
                        $channel->addCurrency($this->getCurrency($currencyCode));
                    }
                }

                $this->persist($channel);
            } catch (\InvalidArgumentException $e) {
                $this->createChannel(
                    $data['code'],
                    $data['label'],
                    $this->listToArray($data['locales']),
                    $category,
                    $this->listToArray($data['currencies'])
                );
            }
        }
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following attributes?:$/
     */
    public function theFollowingAttributes(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $data = array_merge(
                array(
                    'scopable'    => 'no',
                    'localizable' => 'no',
                    'group'       => null,
                    'type'        => 'pim_catalog_text'
                ),
                $data
            );

            $attribute = $this->getProductManager()->createAttribute($data['type']);

            $attribute->setCode($data['code']);
            $attribute->setScopable($data['scopable'] === 'yes');
            $attribute->setTranslatable($data['localizable'] === 'yes');

            $attribute->setLocale('en_US');
            $attribute->setLabel($data['label']);

            if (isset($data['group'])) {
                $group = $this->getAttributeGroup($data['group']);
                $attribute->setGroup($group);
            }

            $this->persist($attribute);
        }
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following attribute label translations:$/
     */
    public function theFollowingAttributeLabelTranslations(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $this
                ->getAttribute($data['attribute'])
                ->setLocale($this->getLocaleCode($data['lang']))
                ->setLabel($data['label']);
        }

        $this->flush();
    }

    /**
     * @param string    $entityName
     * @param string    $id
     * @param TableNode $table
     *
     * @Given /^the following (\w+) "([^"]*)" updates:$/
     */
    public function theFollowingUpdates($entityName, $id, TableNode $table)
    {
        $entity = $this->{'get'.ucfirst($entityName)}($id);

        foreach ($table->getHash() as $data) {
            $audit = new Audit();
            $audit->setAction($data['action']);
            $audit->setLoggedAt(new \DateTime($data['loggedAt']));
            $audit->setObjectId($entity->getId());
            $audit->setObjectClass(get_class($entity));
            $audit->setObjectName((string) $entity);
            $audit->setVersion(1);
            list($field, $change) = explode(': ', $data['change']);
            list($old, $new) = explode(' => ', $change);
            $audit->setData(array($field => array('old' => $old, 'new' => $new)));
            $user = $this->getUser($data['updatedBy']);
            $audit->setUsername($user->getUsername());
            $audit->setUser($user);
            $this->persist($audit);
        }
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following jobs?:$/
     */
    public function theFollowingJobs(TableNode $table)
    {
        $registry = $this->getContainer()->get('oro_batch.connectors');

        foreach ($table->getHash() as $data) {
            $jobInstance = new JobInstance($data['connector'], $data['type'], $data['alias']);
            $jobInstance->setCode($data['code']);
            $jobInstance->setLabel($data['label']);

            $job = $registry->getJob($jobInstance);
            $jobInstance->setJob($job);

            $this->persist($jobInstance);
        }
    }

    /**
     * @param string    $code
     * @param TableNode $table
     *
     * @Given /^the following job "([^"]*)" configuration:$/
     */
    public function theFollowingJobConfiguration($code, TableNode $table)
    {
        $registry    = $this->getContainer()->get('oro_batch.connectors');
        $jobInstance = $this->getJobInstance($code);
        $job         = $registry->getJob($jobInstance);
        $steps       = $job->getSteps();

        foreach ($table->getHash() as $data) {
            $value = $this->replacePlaceholders($data['value']);
            if (in_array($value, array('yes', 'no'))) {
                $value = 'yes' === $value;
            }
            $config[$data['element']][$data['property']] = $value;
        }
        $config = array_merge(array('reader' => array(), 'processor' => array(), 'writer' => array()), $config);
        $steps[0]->setConfiguration($config);
        $jobInstance->setJob($job);

        $this->flush();
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following attribute requirements:$/
     */
    public function theFollowingAttributeRequirements(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $requirement = new AttributeRequirement();

            $attribute = $this->getAttribute($data['attribute']);
            $channel   = $this->getChannel($data['scope']);
            $family    = $this->getFamily($data['family']);

            $requirement->setAttribute($attribute);
            $requirement->setChannel($channel);
            $requirement->setFamily($family);

            $requirement->setRequired($data['required'] === 'yes');

            $this->persist($requirement);
        }

        $this->flush();
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following product groups?:$/
     */
    public function theFollowingProductGroups(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $code = $data['code'];
            $label = $data['label'];
            $type = $data['type'];
            $attributes = ($data['attributes'] == '') ? array() : explode(', ', $data['attributes']);

            $products = (isset($data['products'])) ? explode(', ', $data['products']) : array();

            $this->createGroup($code, $label, $type, $attributes, $products);
        }
    }

    /**
     * @param TableNode $table
     *
     * @Given /^the following associations?:$/
     */
    public function theFollowingAssociations(TableNode $table)
    {
        foreach ($table->getHash() as $data) {
            $code = $data['code'];
            $label = isset($data['label']) ? $data['label'] : null;

            $this->createAssociation($code, $label);
        }
    }

    /**
     * @Given /^there is no identifier attribute$/
     */
    public function thereIsNoIdentifierAttribute()
    {
        $attributes = $this->getRepository('PimCatalogBundle:ProductAttribute')
                ->findBy(array('attributeType' => 'pim_catalog_identifier'));

        foreach ($attributes as $attribute) {
            $this->remove($attribute);
        }
    }

    /**
     * @param string $attribute
     * @param string $options
     *
     * @Given /^the following "([^"]*)" attribute options: (.*)$/
     */
    public function theFollowingAttributeOptions($attribute, $options)
    {
        $attribute = $this->getAttribute(strtolower($attribute));
        foreach ($this->listToArray($options) as $option) {
            $attribute->addOption($this->createOption($option));
        }

        $this->flush();
    }

    /**
     * @param string $lang
     * @param string $attribute
     * @param string $identifier
     * @param string $value
     *
     * @Given /^the (\w+) (\w+) of (\w+) should be "([^"]*)"$/
     */
    public function theOfShouldBe($lang, $attribute, $identifier, $value)
    {
        $productValue = $this->getProductValue($identifier, strtolower($attribute), $this->locales[$lang]);

        assertEquals($value, $productValue->getData());
    }

    /**
     * @param string    $attribute
     * @param string    $products
     * @param TableNode $table
     *
     * @Given /^the prices "([^"]*)" of products (.*) should be:$/
     */
    public function thePricesOfProductsShouldBe($attribute, $products, TableNode $table)
    {
        foreach ($this->listToArray($products) as $identifier) {
            $productValue = $this->getProductValue($identifier, strtolower($attribute));

            foreach ($table->getHash() as $price) {
                assertEquals($price['amount'], $productValue->getPrice($price['currency'])->getData());
            }
        }
    }

    /**
     * @param string    $attribute
     * @param string    $products
     * @param TableNode $table
     *
     * @return null
     * @Given /^the options "([^"]*)" of products (.*) should be:$/
     */
    public function theOptionsOfProductsShouldBe($attribute, $products, TableNode $table)
    {
        foreach ($this->listToArray($products) as $identifier) {
            $productValue = $this->getProductValue($identifier, strtolower($attribute));
            $options = $productValue->getOptions();
            $optionCodes = $options->map(
                function ($option) {
                    return $option->getCode();
                }
            );

            assertEquals(count($table->getHash()), $options->count());
            foreach ($table->getHash() as $data) {
                assertContains($data['value'], $optionCodes);
            }
        }
    }

    /**
     * @param string $attribute
     * @param string $products
     * @param string $filename
     *
     * @Given /^the file "([^"]*)" of products (.*) should be "([^"]*)"$/
     */
    public function theFileOfShouldBe($attribute, $products, $filename)
    {
        foreach ($this->listToArray($products) as $identifier) {
            $productValue = $this->getProductValue($identifier, strtolower($attribute));
            $media = $productValue->getMedia();
            $this->getEntityManager()->refresh($media);
            assertEquals($filename, $media->getOriginalFilename());
        }
    }

    /**
     * @param string $attribute
     * @param string $products
     * @param string $data
     *
     * @Given /^the metric "([^"]*)" of products (.*) should be "([^"]*)"$/
     */
    public function theMetricOfProductsShouldBe($attribute, $products, $data)
    {
        foreach ($this->listToArray($products) as $identifier) {
            $productValue = $this->getProductValue($identifier, strtolower($attribute));
            assertEquals($data, $productValue->getMetric()->getData());
        }
    }

    /**
     * @param PyStringNode $string
     *
     * @Given /^the following file to import:$/
     */
    public function theFollowingFileToImport(PyStringNode $string)
    {
        $this->placeholderValues['file to import'] = $filename =
            sprintf('/tmp/pim-import/behat-import-%s.csv', substr(md5(rand()), 0, 7));
        @mkdir(dirname($filename), 0777, true);

        file_put_contents($filename, (string) $string);
    }

    /**
     * @Given /^import directory of "([^"]*)" contain the following media:$/
     */
    public function importDirectoryOfContainTheFollowingMedia($code, TableNode $table)
    {
        $path = $this
            ->getJobInstance($code)
            ->getJob()
            ->getSteps()[0]
            ->getReader()
            ->getFilePath();

        $path = dirname($path);

        foreach ($table->getRows() as $data) {
            copy(__DIR__ . '/fixtures/'. $data[0], rtrim($path, '/') . '/' .$data[0]);
        }
    }

    /**
     * @param integer $expectedTotal
     *
     * @Then /^there should be (\d+) products?$/
     */
    public function thereShouldBeProducts($expectedTotal)
    {
        $total = count($this->getProductManager()->getFlexibleRepository()->findAll());

        assertEquals($expectedTotal, $total);
    }

    /**
     * @param string    $identifier
     * @param TableNode $table
     *
     * @Given /^the product "([^"]*)" should have the following values?:$/
     */
    public function theProductShouldHaveTheFollowingValues($identifier, TableNode $table)
    {
        $this->getEntityManager()->clear();
        $product = $this->getProduct($identifier);
        $this->getEntityManager()->refresh($product);

        foreach ($table->getRowsHash() as $code => $value) {
            if ('media' === $this->getAttribute($code)->getBackendType()) {
                // media filename is auto generated during media handling and cannot be guessed
                // (it contains a timestamp)
                if ('**empty**' === $value) {
                    assertEmpty((string) $product->getValue($code));
                } else {
                    assertTrue(false !== strpos((string) $product->getValue($code), $value));
                }
            } else {
                assertEquals($value, (string) $product->getValue($code));
            }
        }
    }

    /**
     * @param string $username
     *
     * @Then /^there should be a "([^"]*)" user$/
     */
    public function thereShouldBeAUser($username)
    {
        $this->getUser($username);
    }

    /**
     * @Given /^family of "([^"]*)" should be "([^"]*)"$/
     */
    public function familyOfShouldBe($productCode, $familyCode)
    {
        $family = $this->getProduct($productCode)->getFamily();
        if (!$family) {
            throw \Exception(sprintf('Product "%s" doesn\'t have a family', $productCode));
        }
        assertEquals($familyCode, $family->getCode());
    }

    /**
     * @param string $username
     *
     * @return User
     */
    public function getUser($username)
    {
        return $this->getEntityOrException('OroUserBundle:User', array('username' => $username));
    }

    /**
     * @param string $sku
     *
     * @return Product
     */
    public function getProduct($sku)
    {
        $product = $this->getProductManager()->findByIdentifier($sku);

        return $product ?: $this->createProduct($sku);
    }

    /**
     * @param string $username
     * @param string $password
     * @param string $apiKey
     *
     * @return User
     */
    public function getOrCreateUser($username, $password = null, $apiKey = null)
    {
        if ($user = $this->getRepository('OroUserBundle:User')->findOneBy(array('username' => $username))) {
            return $user;
        }

        return $this->createUser($username, $password, $apiKey);
    }

    /**
     * @param string $code
     *
     * @return GroupType
     */
    public function getGroupType($code)
    {
        return $this->getEntityOrException(
            'PimCatalogBundle:GroupType',
            array(
                'code' => $code
            )
        );
    }

    /**
     * @param string $code
     *
     * @return ProductAttribute
     */
    public function getAttribute($code)
    {
        return $this->getEntityOrException(
            'PimCatalogBundle:ProductAttribute',
            array(
                'code' => $code
            )
        );
    }

    /**
     * @param string $language
     *
     * @return string
     */
    public function getLocaleCode($language)
    {
        if ('default' === $language) {
            return $language;
        }

        if (!isset($this->locales[$language])) {
            throw new \InvalidArgumentException(sprintf('Undefined language "%s"', $language));
        }

        return $this->locales[$language];
    }

    /**
     * @param string $name
     *
     * @return AttributeGroup|null
     */
    public function getAttributeGroup($name)
    {
        try {
            return $this->getEntityOrException(
                'PimCatalogBundle:AttributeGroup',
                array(
                    'code' => $this->camelize($name)
                )
            );
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * @param string $code
     *
     * @return Family
     */
    public function getFamily($code)
    {
        return $this->getEntityOrException('PimCatalogBundle:Family', array('code' => $code));
    }

    /**
     * @param string $code
     *
     * @return Channel
     */
    public function getChannel($code)
    {
        return $this->getEntityOrException('PimCatalogBundle:Channel', array('code' => $code));
    }

    /**
     * Get currency
     *
     * @param string $code
     *
     * @return Currency
     */
    public function getCurrency($code)
    {
        return $this->getEntityOrException('PimCatalogBundle:Currency', array('code' => $code));
    }

    /**
     * @param string $code
     *
     * @return Category
     */
    public function getCategory($code)
    {
        return $this->getEntityOrException('PimCatalogBundle:Category', array('code' => $code));
    }

    /**
     * @param string $code
     *
     * @return Job
     */
    public function getJobInstance($code)
    {
        return $this->getEntityOrException('OroBatchBundle:JobInstance', array('code' => $code));
    }

    /**
     * @param string $code
     *
     * @return Association
     */
    public function getAssociation($code)
    {
        return $this->getEntityOrException('PimCatalogBundle:Association', array('code' => $code));
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public function replacePlaceholders($value)
    {
        if (false !== strpos($value, '{{') && false !== strpos($value, '}}')) {
            $key = trim(str_replace(array('{{', '}}'), '', $value));
            if (array_key_exists($key, $this->placeholderValues)) {
                return $this->placeholderValues[$key];
            }
        }

        return $value;
    }

    /**
     * @param string $identifier
     * @param string $attribute
     * @param string $locale
     * @param string $scope
     *
     * @throws InvalidArgumentException
     *
     * @return ProductValue
     */
    private function getProductValue($identifier, $attribute, $locale = null, $scope = null)
    {
        $product = $this->getProductManager()->findByIdentifier($identifier);
        if (!$product) {
            throw new \InvalidArgumentException(
                sprintf('Could not find product with identifier "%s"', $identifier)
            );
        }

        $productValue = $product->getValue($attribute, $locale, $scope);
        if (!$productValue) {
            throw new \InvalidArgumentException(
                sprintf('Could not find product value for attribute "%s" in locale "%s"', $attribute, $locale)
            );
        }
        $this->getEntityManager()->refresh($productValue);

        return $productValue;
    }

    /**
     * @param string  $code
     * @param boolean $isVariant
     *
     * @return GroupType
     */
    private function createGroupType($code, $isVariant)
    {
        $type = new GroupType();
        $type->setCode($code);
        $type->setVariant($isVariant);

        $this->persist($type);

        return $type;
    }

    /**
     * @param mixed $data
     *
     * @return Product
     */
    private function createProduct($data)
    {
        $product = $this->getProductManager()->createFlexible();
        $sku     = $this->getAttribute('SKU');
        $value   = $this->createValue($sku, $data);

        $product->addValue($value);
        $this->persist($product);

        return $product;
    }

    /**
     * @param string  $label
     * @param boolean $translatable
     * @param string  $type
     * @param boolean $showInGrid
     *
     * @return ProductAttribute
     */
    private function createAttribute($label, $translatable = true, $type = 'text', $showInGrid = false)
    {
        $attribute = $this->getProductManager()->createAttribute($this->getAttributeType($type));
        $attribute->setCode($this->camelize($label));
        $attribute->setLocale('en_US')->setLabel($label); //TODO translation refactoring
        $attribute->setTranslatable($translatable);
        $attribute->setUseableAsGridColumn($showInGrid);
        $attribute->setUseableAsGridFilter($showInGrid);
        if ('identifier' === $type) {
            $attribute->setUnique(true);
            $attribute->setRequired(true);
        }
        $this->persist($attribute);

        return $attribute;
    }

    /**
     * @param string $type
     *
     * @return string
     */
    private function getAttributeType($type)
    {
        if (!isset($this->attributeTypes[$type])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Attribute type "%s" is not defined. Please add it in the %s::$attributeTypes property',
                    $type,
                    get_class($this)
                )
            );
        }

        return $this->attributeTypes[$type];
    }

    /**
     * @param ProductAttribute $attribute
     * @param mixed            $data
     * @param string           $locale
     * @param string           $scope
     *
     * @return ProductValue
     */
    private function createValue(ProductAttribute $attribute, $data = null, $locale = null, $scope = null)
    {
        $manager = $this->getProductManager();

        $value = $manager->createFlexibleValue();
        $value->setAttribute($attribute);

        switch ($attribute->getAttributeType()) {
            case $this->attributeTypes['prices']:
                $prices = $this->createPricesFromString($data);
                foreach ($prices as $price) {
                    $value->addPrice($price);
                }
                break;

            case $this->attributeTypes['image']:
            case $this->attributeTypes['file']:
                $media = $this->createMedia($data);
                $value->setMedia($media);
                break;

            default:
                $value->setData($data);
        }
        $value->setLocale($locale);
        $value->setScope($scope);

        return $value;
    }

    /**
     * @param string $code
     *
     * @return Locale
     */
    private function getLocale($code)
    {
        try {
            $lang = $this->getEntityOrException('PimCatalogBundle:Locale', array('code' => $code));
        } catch (\InvalidArgumentException $e) {
            $lang = $this->createLocale($code);
        }

        return $lang;
    }

    /**
     * @param string $code
     */
    private function createLocale($code)
    {
        $locale = new Locale();
        $locale->setCode($code);

        $this->persist($locale);

        return $locale;
    }

    /**
     * @param string $code
     *
     * @return Category
     */
    private function createTree($code)
    {
        $tree = new Category();
        $tree->setCode($code);
        $this->persist($tree);

        return $tree;
    }

    /**
     * @param string   $code
     * @param string   $label
     * @param string[] $locales
     * @param Category $tree
     * @param string[] $currencies
     */
    private function createChannel($code, $label = null, $locales = array(), $tree = null, $currencies = array())
    {
        $channel = new Channel();
        $channel->setCode($code);

        if ($label === null) {
            $label = ucfirst($code);
        }
        $channel->setLabel($label);

        if ($tree !== null) {
            $channel->setCategory($tree);
        }

        foreach ($locales as $localeCode) {
            $channel->addLocale($this->getLocale($localeCode));
        }

        foreach ($currencies as $currencyCode) {
            $channel->addCurrency($this->getCurrency($currencyCode));
        }

        $this->persist($channel);
    }

    /**
     * @param string $code
     * @param string $label
     * @param string $type
     * @param array  $attributes
     * @param array  $products
     */
    private function createGroup($code, $label, $type, array $attributes, array $products = array())
    {
        $group = new Group();
        $group->setCode($code);
        $group->setLocale('en_US')->setLabel($label); // TODO translation refactoring

        $type = $this->getGroupType($type);
        $group->setType($type);

        foreach ($attributes as $attributeCode) {
            $attribute = $this->getAttribute($attributeCode);
            $group->addAttribute($attribute);
        }

        foreach ($products as $sku) {
            if (!empty($sku)) {
                $product = $this->getProduct($sku);
                $group->addProduct($product);
                $product->addGroup($group);
            }
        }

        $this->persist($group);
    }

    /**
     * @param string $code
     * @param string $label
     */
    private function createAssociation($code, $label)
    {
        $association = new Association();
        $association->setCode($code);
        $association->setLocale('en_US')->setLabel($label);

        $this->persist($association);
    }

    /**
     * @param string $label
     *
     * @return Role
     */
    private function getRoleOrCreate($label)
    {
        try {
            $role = $this->getEntityOrException('OroUserBundle:Role', array('role' => $label));
        } catch (\InvalidArgumentException $e) {
            $role = new Role($label);
            $this->persist($role);
        }

        return $role;
    }

    /**
     * @param string $code
     *
     * @return Category
     */
    private function getCategoryOrCreate($code)
    {
        try {
            $category = $this->getCategory($code);
        } catch (\InvalidArgumentException $e) {
            $category = new Category();
            $category->setCode($code);
            $this->persist($category);
        }

        return $category;
    }

    /**
     * @param string $namespace
     * @param array  $criteria
     *
     * @return object
     */
    private function getEntityOrException($namespace, array $criteria)
    {
        $entity = $this->getRepository($namespace)->findOneBy($criteria);

        if (!$entity) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Could not find "%s" with criteria %s',
                    $namespace,
                    print_r($criteria, true)
                )
            );
        }

        return $entity;
    }

    /**
     * @param string $code
     *
     * @return Group
     */
    public function getProductGroup($code)
    {
        return $this->getEntityOrException('PimCatalogBundle:Group', array('code' => $code));
    }

    /**
     * @param Family $family
     * @param string $content
     * @param string $locale
     *
     * @return FamilyTranslation
     */
    private function createFamilyTranslation(Family $family, $content, $locale = 'en_US')
    {
        $translation = new FamilyTranslation();
        $translation->setLabel($content);
        $translation->setLocale($locale);
        $translation->setForeignKey($family);

        $this->persist($translation);

        return $translation;
    }

    /**
     * @param string $prices
     *
     * @return ArrayCollection:Price
     */
    private function createPricesFromString($prices)
    {
        $prices = explode(',', $prices);
        $data = array();

        foreach ($prices as $price) {
            $price = explode(' ', trim($price));
            $amount = array_filter(
                $price,
                function ($item) {
                    return preg_match('/^[0-9]+(\.[0-9]+)?$/', $item);
                }
            );
            $amount = reset($amount);
            if (!$amount) {
                continue;
            }
            $currency = array_filter(
                $price,
                function ($item) {
                    return preg_match('/^[a-zA-Z]+(.+)$/', $item);
                }
            );
            $currency = !empty($currency) ? reset($currency) : 'EUR';
            $data[] = $this->createPrice($amount, $currency);
        }

        return new ArrayCollection($data);
    }

    private function createMedia($file)
    {
        $media = new Media();
        if ($file) {
            $media->setFile(new File(__DIR__ . '/fixtures/' . $file));
            $this->getMediaManager()->handle($media, 'behat');
        }

        return $media;
    }

    /**
     * @param string $data
     * @param string $currency
     *
     * @return ProductPrice
     */
    private function createPrice($data, $currency = 'EUR')
    {
        $price = new ProductPrice();
        $price->setData($data);
        $price->setCurrency($currency);

        return $price;
    }

    /**
     * @param string $username
     * @param strng  $password
     * @param string $apiKey
     *
     * @return User
     */
    private function createUser($username, $password = null, $apiKey = null)
    {
        $password     = $password ?: $username . 'pass';
        $apiKey       = $apiKey ?: $username . '_api_key';
        $email        = $username.'@example.com';
        $locale       = 'en_US';
        $localeOption = null;
        $scope        = 'ecommerce';
        $scopeOption  = null;

        $user = $this->getUserManager()->createUser();
        $user
            ->setUsername($username)
            ->setPlainPassword($password)
            ->setFirstname('John')
            ->setLastname('Doe')
            ->setEmail($email)
            ->addRole($this->getRoleOrCreate('ROLE_ADMINISTRATOR'));

        $manager = $this->getContainer()->get('oro_user.manager.flexible');

        $localeAttribute = $manager->getAttributeRepository()->findOneBy(array('code' => 'cataloglocale'));

        if (!$localeAttribute) {
            $localeAttribute = $manager->createAttribute('oro_flexibleentity_simpleselect');
            $localeAttribute->setCode('cataloglocale')->setLabel('cataloglocale');
            foreach ($this->locales as $localeCode) {
                $option = $manager->createAttributeOption();
                $optionValue = $manager->createAttributeOptionValue()->setValue($localeCode);
                $option->addOptionValue($optionValue);
                $localeAttribute->addOption($option);
                if ($locale == $localeCode) {
                    $localeOption = $option;
                }
            }
            $this->persist($localeAttribute);
        } else {
            $localeOption = $localeAttribute->getOptions()->filter(
                function ($option) use ($locale) {
                    return $option->getOptionValue()->getValue() === $locale;
                }
            )->first();
        }

        $localeValue = $manager->createFlexibleValue();
        $localeValue->setAttribute($localeAttribute);
        $localeValue->setOption($localeOption);
        $user->addValue($localeValue);

        $scopeAttribute = $manager->getAttributeRepository()->findOneBy(array('code' => 'catalogscope'));

        if (!$scopeAttribute) {
            $scopeAttribute = $manager->createAttribute('oro_flexibleentity_simpleselect');
            $scopeAttribute->setCode('catalogscope')->setLabel('catalogscope');
            foreach (array_keys($this->channels) as $scopeCode) {
                $option = $manager->createAttributeOption();
                $optionValue = $manager->createAttributeOptionValue()->setValue($scopeCode);
                $option->addOptionValue($optionValue);
                $scopeAttribute->addOption($option);
                if ($scope == $scopeCode) {
                    $scopeOption = $option;
                }
            }
            $this->persist($scopeAttribute);
        } else {
            $scopeOption = $scopeAttribute->getOptions()->filter(
                function ($option) use ($scope) {
                    return $option->getOptionValue()->getValue() === $scope;
                }
            )->first();
        }

        $scopeValue = $manager->createFlexibleValue();
        $scopeValue->setAttribute($scopeAttribute);
        $scopeValue->setOption($scopeOption);
        $user->addValue($scopeValue);

        $this->getUserManager()->updateUser($user);

        $api = new UserApi();
        $api->setApiKey($username.'_api_key')->setUser($user);

        $this->persist($api);

        return $user;
    }

    /**
     * Create an attribute option entity
     *
     * @param string $code
     *
     * @return AttributeOption
     */
    private function createOption($code)
    {
        $option = new AttributeOption();
        $option->setCode($code);

        return $option;
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private function camelize($string)
    {
        return Inflector::camelize(str_replace(' ', '_', strtolower($string)));
    }

    /**
     * Persist an entity
     *
     * @param object  $entity
     * @param boolean $flush
     */
    private function persist($entity, $flush = true)
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->flush();
        }
    }

    /**
     * Remove an entity
     *
     * @param object  $entity
     * @param boolean $flush
     */
    private function remove($entity, $flush = true)
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->flush();
        }
    }

    /**
     * Flush
     */
    private function flush()
    {
        $this->getEntityManager()->flush();
    }

    /**
     * @return \Doctrine\ORM\EntityManager
     */
    private function getEntityManager()
    {
        return $this->getMainContext()->getEntityManager();
    }

    /**
     * @param string $repository
     *
     * @return Repository
     */
    private function getRepository($repository)
    {
        return $this->getEntityManager()->getRepository($repository);
    }

    /**
     * @return \Pim\Bundle\CatalogBundle\Manager\ProductManager
     */
    private function getProductManager()
    {
        return $this->getContainer()->get('pim_catalog.manager.product');
    }

    /**
     * @return \Pim\Bundle\CatalogBundle\Manager\MediaManager
     */
    private function getMediaManager()
    {
        return $this->getContainer()->get('pim_catalog.manager.media');
    }

    /**
     * @return \Oro\Bundle\UserBundle\Entity\UserManager
     */
    private function getUserManager()
    {
        return $this->getContainer()->get('oro_user.manager');
    }

    /**
     * @return \Gaufrette\Filesystem
     */
    private function getPimFilesystem()
    {
        return $this->getContainer()->get('pim_filesystem');
    }

    /**
     * @return \Symfony\Component\DependencyInjection\ContainerInterface
     */
    private function getContainer()
    {
        return $this->getMainContext()->getContainer();
    }

    /**
     * @param string $list
     *
     * @return array
     */
    private function listToArray($list)
    {
        return $this->getMainContext()->listToArray($list);
    }
}
