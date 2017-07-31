<?php
namespace app\components;

use app\modules\backCall\models\BackCall;
use app\modules\order\models\Order;
use app\modules\order\models\Payment;
use app\modules\policy\models\Insurant;
use yii\base\Component;
use yii\base\Event;
use yii\helpers\Json;
use yii\helpers\VarDumper;
use yii\httpclient\Client;

/**
 * Class Amo
 * @package app\components
 */
class Amo extends Component{

    const TYPE_LEAD = 'lead';
    const TYPE_CONTACT = 'contact';

    /**
     * @var []
     */
    public static $fields;

    /**
     * @var string
     */
    public $objectType;

    /**
     * @var string
     */
    private $authCookie;

    /**
     * Set custom fields from params and subscribe to events
     */
    public function init()
    {
        self::$fields = \Yii::$app->params['amo-crm']['custom-fields'];

        if (defined('YII_ENV') && YII_ENV != 'dev' && YII_ENV != 'test') {
            //сохранение после заполненных телефона и имейла у страхователя
            Event::on(Order::className(), Order::EVENT_AFTER_EMAIL_AND_PHONE_SET, [$this, 'orderAfterEmailAndPhoneSet']);

            //сохранение после загрузки документов
            Event::on(Order::className(), Order::EVENT_AFTER_DOCUMENTS_UPLOADED, [$this, 'orderAfterDocumentsUploaded']);

            //обновление статуса после оплаты
            Event::on(Order::className(), Order::EVENT_BEFORE_PAID, [$this, 'orderBeforePaid']);

            //сохранение после заказа обратного звонка
            Event::on(BackCall::className(), BackCall::EVENT_AFTER_INSERT, [$this, 'backCallAfterInsert']);

            //сохранение после сохранения страхователя
            Event::on(Insurant::className(), Insurant::EVENT_AFTER_INSERT, [$this, 'insurantAfterInsert']);
        }

        parent::init();
    }

    /**
     * Authenticates request
     * @return string|\yii\web\Cookie
     */
    public function auth()
    {
        if (!$this->authCookie){
            $subdomain = \Yii::$app->params['amo-crm']['subdomain'];
            $link = "https://$subdomain.amocrm.ru/private/api/auth.php?type=json";

            $client = new Client();
            $response = $client->createRequest()
                ->setMethod('post')
                ->setUrl($link)
                ->setData([
                    'USER_LOGIN' => \Yii::$app->params['amo-crm']['user-login'],
                    'USER_HASH' => \Yii::$app->params['amo-crm']['user-hash'],
                ])
                ->send();

            if ($response->isOk) {
                $this->authCookie =  $response->getCookies()->get('session_id');
            }
        }

        return $this->authCookie;
    }

    /**
     * Returns info about amo-crm internal vocabulary
     * @return bool|mixed
     */
    public function info()
    {
        $subdomain = \Yii::$app->params['amo-crm']['subdomain'];
        $link = "https://$subdomain.amocrm.ru/private/api/v2/json/accounts/current";

        $client = new Client();
        $response = $client->createRequest()
            ->setMethod('get')
            ->setUrl($link)
            ->addCookies([$this->auth()])
            ->send();

        if ($response->isOk) {
            return $response->data;
        }

        return false;
    }

    /**
     * Both add and update function in one
     * @param $type
     * @param $data
     * @return bool
     */
    public function set($type, $data)
    {
        $operation = isset($data['id']) ? 'update' : 'add';

        $set['request'][$type . 's'][$operation] = [$data];

        $subdomain = \Yii::$app->params['amo-crm']['subdomain'];
        $link = "https://$subdomain.amocrm.ru/private/api/v2/json/{$type}s/set";

        $client = new Client();
        $response = $client->createRequest()
            ->setMethod('post')
            ->setUrl($link)
            ->setData($set)
            ->addCookies([$this->auth()])
            ->send();

        if ($response->isOk) {
            return $response->data['response'][$type . 's'][$operation][0]['id'];
        }

        return false;
    }

    /**
     * Adds custom field to data set
     * @param $name
     * @param $value
     * @param $enum
     * @return array
     */
    protected function addCustomField($name, $value, $enum = null)
    {
        if ($enum){
            return [
                'id' => self::$fields[$name],
                'values' => [
                    [
                        'value' => $value,
                        'ENUM' => $enum,
                    ]
                ]
            ];
        }
        return [
            'id' => self::$fields[$name],
            'values' => [
                [
                    'value' => $value,
                ]
            ]
        ];


    }

    public function orderAfterEmailAndPhoneSet(Event $event) {
        /** @var Order $order */
        $order = $event->sender;

        //lead
        $data = [
            'name' => 'Осаго ' . $order->code,
            'price' => $order->policy->price,
            'last_modified' => time(),
            'custom_fields' => [
                [
                    'id' => self::$fields['order_number'],
                    'values' => [
                        [
                            'value' => $order->code,
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['link'],
                    'values' => [
                        [
                            'value' => 'https://osago.prosto.insure/order/'.$order->code,
                        ]
                    ]
                ],
            ],
        ];
        if ($order->amo_lead_id) $data['id'] = $order->amo_lead_id;
        $leadId = $this->set(self::TYPE_LEAD, $data);

        //contact
        $data = [
            'name' => 'Cтрахователь',
            'linked_leads_id' => [$leadId],
            'last_modified' => time(),
            'custom_fields' => [
                [
                    'id' => self::$fields['email'],
                    'values' => [
                        [
                            'value' => $order->email,
                            'enum' => 'WORK'
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['phone'],
                    'values' => [
                        [
                            'value' => $order->phone,
                            'enum' => 'WORK'
                        ]
                    ]
                ],
            ],
        ];
        if ($order->amo_contact_id) $data['id'] = $order->amo_contact_id;
        $contactId = $this->set(self::TYPE_CONTACT, $data);

        $order->amo_contact_id = $contactId;
        $order->amo_lead_id = $leadId;
        $order->save();
    }

    public function orderAfterDocumentsUploaded(Event $event) {
        /** @var Order $order */
        $order = $event->sender;

        //lead
        $data = [
            'name' => 'Осаго ' . $order->code,
            'tags' => 'загружены-документы',
            'price' => $order->policy->price,
            'last_modified' => time(),
            'custom_fields' => [
                [
                    'id' => self::$fields['order_number'],
                    'values' => [
                        [
                            'value' => $order->code,
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['link'],
                    'values' => [
                        [
                            'value' => 'https://osago.prosto.insure/order/'.$order->code,
                        ]
                    ]
                ],
            ],
        ];
        if ($order->amo_lead_id) $data['id'] = $order->amo_lead_id;
        $leadId = $this->set(self::TYPE_LEAD, $data);


        //contact
        $data = [
            'name' => 'Осаго документы ' . $order->code,
            'linked_leads_id' => [$leadId],
            'last_modified' => time(),
            'custom_fields' => [
                [
                    'id' => self::$fields['email'],
                    'values' => [
                        [
                            'value' => $order->email,
                            'enum' => 'WORK'
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['phone'],
                    'values' => [
                        [
                            'value' => $order->phone,
                            'enum' => 'WORK'
                        ]
                    ]
                ],
            ],
        ];
        if ($order->amo_contact_id) $data['id'] = $order->amo_contact_id;
        $contactId = $this->set(self::TYPE_CONTACT, $data);

        $order->amo_contact_id = $contactId;
        $order->amo_lead_id = $leadId;
        $order->save();

        return true;
    }

    public function orderBeforePaid(Event $event) {
        /** @var Order $order */
        $order = $event->sender;

        //lead
        $data = [
            'id' => $order->amo_lead_id,
            'price' => $order->total_price,
            'tags' => $order->getPayment()->payment_type == Payment::TYPE_CARD ?
                'доставка оплачено картой' : 'доставка оплата курьеру',
            'last_modified' => time(),
            'custom_fields' => [
                [
                    'id' => self::$fields['company'],
                    'values' => [
                        [
                            'value' => $order->policy->insuranceCompany->label,
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['power'],
                    'values' => [
                        [
                            'value' => $order->policy->getAuto()->power,
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['youngest'],
                    'values' => [
                        [
                            'value' => $order->policy->getDriversMinimumAge(),
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['inexperienced'],
                    'values' => [
                        [
                            'value' => $order->policy->getDriversMinimumExperience(),
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['mark'],
                    'values' => [
                        [
                            'value' => $order->policy->getAuto()->getCarMarkLabel(),
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['model'],
                    'values' => [
                        [
                            'value' => $order->policy->getAuto()->getCarModelLabel(),
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['production_year'],
                    'values' => [
                        [
                            'value' => $order->policy->getAuto()->production_year,
                        ]
                    ]
                ],
                [
                    'id' => self::$fields['delivery_datetime'],
                    'values' => [
                        [
                            'value' => $order->getDelivery()->date . ' от '
                                . $order->getDelivery()->time_from . ' до '
                                . $order->getDelivery()->time_to,
                        ]
                    ]
                ],
            ],
        ];
        $this->set(self::TYPE_LEAD, $data);
    }

    public function backCallAfterInsert(Event $event) {
        /** @var BackCall $backCall */
        $backCall = $event->sender;

        if ($backCall->orderCode) {
            $order = Order::findOne(['code' => $backCall->orderCode]);
            if ($order) {
                //lead
                $data = [
                    'name' => 'Осаго ' . $order->code,
                    'tags' => 'обратный звонок процесс оформления',
                    'price' => $order->policy->price,
                    'last_modified' => time(),
                    'custom_fields' => [
                        $this->addCustomField('order_number', $order->code),
                        $this->addCustomField('link', 'https://osago.prosto.insure/order/'.$order->code),
                        $this->addCustomField('company', $order->policy->insuranceCompany->label),
                        $this->addCustomField(
                            'power',
                            $order->policy->getAuto() ?
                                $order->policy->getAuto()->power :
                                $order->policy->getOfferData()->getPowerTextRepresentation()
                        ),
                        $this->addCustomField(
                            'youngest',
                            !empty($order->policy->getDrivers()) ?
                                $order->policy->getDriversMinimumAge() :
                                $order->policy->getOfferData()->getAgeTextRepresentation()
                        ),
                        $this->addCustomField(
                            'inexperienced',
                            !empty($order->policy->getDrivers()) ?
                                $order->policy->getDriversMinimumExperience() :
                                $order->policy->getOfferData()->getExperienceTextRepresentation()
                        ),
                        $this->addCustomField(
                            'term',
                            $order->policy->getOfferData()->getTermTextRepresentation()
                        ),
                        $this->addCustomField(
                            'car_type',
                            $order->policy->getOfferData()->getCarTypeTextRepresentation()
                        ),
                        $this->addCustomField(
                            'is_multiple_drive',
                            $order->policy->getOfferData()->getIsMultipleDriveTextRepresentation()
                        ),
                        $this->addCustomField(
                            'clear_experience',
                            $order->policy->getOfferData()->getClearExperienceTextRepresentation()
                        ),
                        $this->addCustomField(
                            'region',
                            $order->policy->getOfferData()->getRegionTextRepresentation()
                        ),
                    ],
                ];

                if ($order->amo_lead_id){
                    $data['id'] = $order->amo_lead_id;
                }

                $leadId = $this->set(self::TYPE_LEAD, $data);

                //contact
                $data = [
                    'name' => $backCall->name,
                    'linked_leads_id' => [$leadId],
                    'last_modified' => time(),
                    'custom_fields' => [
                        $this->addCustomField('email', $backCall->email, 'WORK'),
                        $this->addCustomField('phone', $backCall->phone, 'WORK'),
                    ],
                ];

                if ($order->amo_contact_id){
                    $data['id'] = $order->amo_contact_id;
                }

                $contactId = $this->set(self::TYPE_CONTACT, $data);

                $order->amo_contact_id = $contactId;
                $order->amo_lead_id = $leadId;
                $order->save();

                return true;
            }
        }

        //lead
        $data = [
            'name' => 'Осаго ' . $backCall->id . ' без заказа',
            'tags' => 'обратный звонок',
            'price' => 0,
            'last_modified' => time(),
        ];
        $leadId = $this->set(self::TYPE_LEAD, $data);

        //contact
        $data = [
            'name' => $backCall->name,
            'linked_leads_id' => [$leadId],
            'last_modified' => time(),
            'custom_fields' => [
                $this->addCustomField('email', $backCall->email, 'WORK'),
                $this->addCustomField('phone', $backCall->phone, 'WORK'),
            ],
        ];
        $this->set(self::TYPE_CONTACT, $data);

        return true;
    }

    public function insurantAfterInsert(Event $event) {
        /** @var Insurant $insurant */
        $insurant = $event->sender;

        $order = $insurant->policy->getOrder();

        //lead
        $data = [
            'name' => 'Осаго ' . $order->code,
            'tags' => 'страхователь',
            'price' => $order->policy->price,
            'last_modified' => time(),
            'custom_fields' => [
                $this->addCustomField('order_number', $order->code),
                $this->addCustomField('link', 'https://osago.prosto.insure/order/'.$order->code),
            ],
        ];

        if ($order->amo_lead_id){
            $data['id'] = $order->amo_lead_id;
        }

        $leadId = $this->set(self::TYPE_LEAD, $data);

        //contact
        $data = [
            'name' => $insurant->full_name,
            'linked_leads_id' => [$leadId],
            'last_modified' => time(),
            'custom_fields' => [
                $this->addCustomField('email', $insurant->email, 'WORK'),
                $this->addCustomField('phone', $insurant->phone, 'WORK'),
            ],

        ];

        if ($order->amo_contact_id){
            $data['id'] = $order->amo_contact_id;
        }

        $contactId = $this->set(self::TYPE_CONTACT, $data);

        $order->amo_contact_id = $contactId;
        $order->amo_lead_id = $leadId;
        $order->save();
    }
}