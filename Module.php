<?php

namespace mirocow\notification;

use mirocow\notification\components\JobEvent;
use mirocow\notification\components\Notification;
use mirocow\notification\components\Provider;
use mirocow\notification\models\NotificationStatus;
use Yii;
use yii\base\BootstrapInterface;
use yii\db\Expression;
use yii\helpers\Json;

class Module extends \yii\base\Module implements BootstrapInterface
{
    const EVENT_BEFORE_SEND = 'beforeSend';

    const EVENT_AFTER_SEND = 'afterSend';

    public $controllerNamespace = 'mirocow\notification\controllers';

    public $storeNotificationStatus = false;

    public $providers = [];

    private $_providers = [];

    /**
     * @inheritdoc
     */
    public function bootstrap($app)
    {
        foreach ($this->providers as $providerName => $provider){
            if(empty($provider['events'])) continue;
            $this->attachEvents($providerName, $provider);
        }
    }

    /**
     * @param Notification $notification
     */
    public function sendEvent(Notification $notification)
    {
        /** @var Provider $provider */
        $provider = Yii::createObject($notification->data['provider']);
        if(!$provider || !$provider->enabled){
            return;
        }

        $event = new JobEvent([
            'provider' => $notification->data['providerName'],
            'event' => $notification->name,
            'params' => $notification,
        ]);

        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if(!$event->isValid){
            return;
        }

        $statusId = $this->setProviderStatus($notification);
        $provider->send($notification);
        $this->setProviderStatus($notification, $statusId, $provider->status);
        $event->status = $provider->status;
        $this->trigger(self::EVENT_AFTER_SEND, $event);
    }

    /**
     * @param $name
     * @return mixed
     */
    public function provider($name)
    {
        if (!isset($this->_providers[$name])) {
            if (isset($this->providers[$name])) {
                $this->_providers[$name] = Yii::createObject($this->providers[$name]);
            }
        }
        return $this->_providers[$name];
    }

    /**
     * @param $provider
     */
    public function attachEvents($providerName, $provider)
    {
        foreach ($provider['events'] as $className => $events) {
            foreach ($events as $eventName) {
                Notification::on($className, $eventName, [$this, 'sendEvent'], ['providerName' => $providerName, 'provider' => $provider]);
            }
        }
    }

    /**
     * @param $class
     * @return string
     */
    function class_basename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }

    private function setProviderStatus(Notification &$notification, $statusId = null, $ret = null)
    {
        if(!$this->storeNotificationStatus){
            return;
        }

        $providerName = $notification->data['providerName'];
        $event = $notification->name;

        if (!$statusId) {
            $status = new NotificationStatus;
            $status->provider = $providerName;
            $status->event = $event;
            $status->params = Json::encode($notification->getAttributes());
        } else {
            /** @var NotificationStatus $status */
            $status = NotificationStatus::findOne($statusId);
            $status->update_at = new Expression('CURRENT_TIMESTAMP');
            $status->status = Json::encode($ret);
        }

        $status->save();

        return $status->id;
    }

}
