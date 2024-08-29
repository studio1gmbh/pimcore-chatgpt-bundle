<?php

/**
 * Studio1 Kommunikation GmbH
 *
 * This source file is available under following license:
 * - GNU General Public License v3.0 (GNU GPLv3)
 *
 *  @copyright  Copyright (c) Studio1 Kommunikation GmbH (http://www.studio1.de)
 *  @license    https://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace Pdchaudhary\ChatgptPimcoreBundle\Controller;

use OpenAI;
use Pimcore\Controller\FrontendController;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\WebsiteSetting;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends FrontendController
{
    /**
     * @Route("/admin/chatgpt/object-fields")
     */
    public function getFields(Request $request)
    {
        $objectId = $request->get('objectId');
        $object = DataObject::getById((int)$objectId);

        $fieldItems = [];
        if ($object) {
            // Get the class definition
            $class = $object->getClass();

            // Get the field definitions
            $fields = $class->getFieldDefinitions();

            foreach ($fields as $field) {
                if (
                    $field instanceof Data\Textarea
                    || $field instanceof Data\Wysiwyg

                ) {
                    $fieldItems[] =
                    [
                        'id' => $field->getName(),
                        'name' => $field->getTitle(),
                        'is_localizedfield' => false,
                    ];
                }

                if (
                    $field instanceof Localizedfields

                ) {
                    $localizedfields = $field->getFieldDefinitions();
                    foreach ($localizedfields as $localizedfield) {
                        if (
                             $localizedfield instanceof Data\Wysiwyg
                            || $localizedfield instanceof Data\Textarea

                        ) {
                            $fieldItems[] =
                            [
                                'id' => $localizedfield->getName(),
                                'name' => $localizedfield->getTitle(),
                                'is_localizedfield' => true,
                            ];
                        }
                    }
                }
            }
        }

        return new JsonResponse([
            'data' => $fieldItems
        ]);
    }

    /**
     * @Route("/admin/map-description-field")
     *
     */
    public function mapDescriptionField(Request $request)
    {
        $objectId = $request->get('objectId');
        $field = $request->get('field');
        $language = $request->get('language');
        $object = DataObject::getById((int)$objectId);

        if ($object) {
            // Get the class definition
            $class = $object->getClass();

            $description = 'Write '.$class->getName().' '.$field.' for following information';

            if ($language) {
                $description .= ' in '.$language;
            }
            $description .= ":-\n";
            // Get the field definitions
            $fields = $class->getFieldDefinitions();

            foreach ($fields as $field) {
                if (
                    $field instanceof Data\Input
                    || $field instanceof Data\Select
                    || $field instanceof Data\Date
                    || $field instanceof Data\Numeric
                    || $field instanceof Data\DateTime
                    || $field instanceof Data\Multiselect

                ) {
                    $value = $object->{'get'.ucwords($field->getName())}();
                    if ($value) {
                        if (is_array($value) || is_object($value)) {
                            $value = json_encode($value);
                        }
                        $description .= $field->getTitle().':'.$value."\n";
                    }
                }

                if (
                    $field instanceof Localizedfields

                ) {
                    $localizedfields = $field->getFieldDefinitions();
                    foreach ($localizedfields as $localizedfield) {
                        if (
                             $localizedfield instanceof Data\Input
                            || $localizedfield instanceof Data\Select
                            || $localizedfield instanceof Data\Multiselect
                            || $localizedfield instanceof Data\Date
                            || $localizedfield instanceof Data\DateTime
                            || $localizedfield instanceof Data\Numeric

                        ) {
                            $value = $object->{'get'.ucwords($localizedfield->getName())}();
                            if ($value) {
                                $description .= $localizedfield->getTitle().':'.$value."\n";
                            }
                        }
                    }
                }
            }
        }

        return new JsonResponse([
            'data' => $description
        ]);
    }

    /**
     * @Route("/admin/chatgpt/generate-description")
     */
    public function generateDescription(Request $request)
    {
        $objectId = $request->get('objectId');
        $description = $request->get('description');
        $field = $request->get('field');
        $lang = $request->get('lang');
        $max_tokens = $request->get('max_tokens');

        $apiKey = $this->getChatGPTAuthKey();
        $gptModel = $this->getChatGPTModel();

        if (empty($apiKey)) {
            return new JsonResponse([
                'success' => false,
                'message' => "Please provide an API key from OpenAI and add the generated key,
                labeled as 'chatgpt_auth_key', in the website settings."
            ]);
        }
        if (empty($gptModel)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Please provide a valid OpenAI model (for example "text-davinci-003") and it to the
                website settings labled as "chatgpt_model".'
            ]);
        }
        $client = OpenAI::client($apiKey);

        $result = $client->completions()->create([
            'model' => $gptModel,
            'prompt' => $description,
            'max_tokens' => (int)$max_tokens,
            'temperature' => 0.0
        ]);
        $text = '';
        $response = $result->toArray();

        if (isset($response['choices']) && !empty($response['choices'])) {
            $text = $response['choices'][0]['text'];
        }
        $object = DataObject::getById((int)$objectId);
        if ($text) {
            if ($lang) {
                $object->{'set'.ucwords($field)}($text, $lang);
            } else {
                $object->{'set'.ucwords($field)}($text);
            }
            $object->save();

            return new JsonResponse([
                'success' => true,
                'message' => 'The field data was successfully updated using the ChatGPT.'
            ]);
        } else {
            return new JsonResponse([
                'success' => false,
                'message' => 'The AI did not provide any data or information based on description.'
            ]);
        }
    }

    public function getChatGPTAuthKey()
    {
        $authKey = WebsiteSetting::getByName('chatgpt_auth_key') ? WebsiteSetting::getByName('chatgpt_auth_key')->getData() : null;

        return $authKey;
    }

    public function getChatGPTModel()
    {
        $authKey = WebsiteSetting::getByName('chatgpt_model') ? WebsiteSetting::getByName('chatgpt_model')->getData() : null;

        return $authKey;
    }
}
