<?php

namespace Broarm\EventTickets\Model;

use Broarm\EventTickets\Extensions\TicketExtension;
use Exception;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CurrencyField;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Class Buyable
 *
 * @package Broarm\EventTickets
 *
 * @property string Title
 * @property float Price
 * @property int OrderMin
 * @property int OrderMax
 * @property string AvailableFromDate
 * @property string AvailableTillDate
 * @property NumericField AmountField the amount field is set on the TicketForm
 *
 * @method TicketExtension|SiteTree TicketPage()
 */
class Buyable extends DataObject
{
    private static $table_name = 'EventTickets_Buyable';

    /**
     * The default sale start date
     * This defaults to the event start date '-3 week'
     *
     * @var string
     */
    private static $sale_start_threshold = '-3 week';

    /**
     * The default sale end date
     * This defaults to the event start date time '-12 hours'
     *
     * @var string
     */
    private static $sale_end_threshold = '-12 hours';

    private static $db = [
        'Title' => 'Varchar',
        'Price' => 'Currency',
        'IsAvailable' => 'Boolean(1)',
        'AvailableFromDate' => 'DBDatetime',
        'AvailableTillDate' => 'DBDatetime',
        'OrderMin' => 'Int',
        'OrderMax' => 'Int',
        'Capacity' => 'Int',
        'Sort' => 'Int'
    ];

    private static $default_sort = 'Sort ASC, AvailableFromDate DESC';

    private static $has_one = [
        'TicketPage' => SiteTree::class
    ];

    private static $defaults = [
        'OrderMin' => 1,
        'OrderMax' => 5
    ];

    private static $summary_fields = [
        'Title' => 'Title',
        'Price.Nice' => 'Price',
        'AvailableFrom' => 'Available from',
        'AvailableTill' => 'Available till',
        'AvailableSummary' => 'Available',
        'SoldStatus' => 'Sold',
    ];

    private static $searchable_fields = [
        'Title',
        'Price',
        'AvailableFromDate',
        'AvailableTillDate',
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['TicketPageID', 'Sort', 'OrderMin', 'OrderMax', 'AvailableFromDate', 'AvailableTillDate']);
        
        $fields->addFieldsToTab('Root.Main', array(
            $this->getTypeField(),
            TextField::create('Title', _t(__CLASS__ . '.TitleForTicket', 'Title for the ticket')),
            CurrencyField::create('Price', _t(__CLASS__ . '.Price', 'Ticket price')),
            CheckboxField::create('IsAvailable', _t(__CLASS__ . '.IsAvailable', 'Is available')),
            NumericField::create('Capacity', _t(__CLASS__ . '.Capacity', 'Amount available'))
                ->setDescription(_t(__CLASS__ . '.CapacityDescription', 'Amount of tickets available (from this type)')),
            
            $saleStartGroup = FieldGroup::create(_t(__CLASS__ . '.TicetSaleStarts', 'Ticket sale starts'), [
                DatetimeField::create('AvailableFromDate', _t(__CLASS__ . '.AvailableOn', 'Available on')),
                DatetimeField::create('AvailableTillDate', _t(__CLASS__ . '.AvailableTill', 'Available till')),
            ]),
            
            FieldGroup::create(_t(__CLASS__ . '.TicketsPerOrder', 'Allowed tickets per order'), [
                NumericField::create('OrderMin', _t(__CLASS__ . '.OrderMin', 'Minimum')),
                NumericField::create('OrderMax', _t(__CLASS__ . '.OrderMax', 'Maximum'))
            ])->setDescription(_t(__CLASS__ . '.TicketsPerOrderDescription', 'Allowed amount of tickets from this type to be sold at once')),
        ));

        $availableFrom = $this->getAvailableFrom();
        $eventStart = $this->getEventStartDate();
        if ($availableFrom || $eventStart) {
            $saleStartGroup->setDescription(_t(
                __CLASS__ . '.SaleStartDescription', 
                'If no dates are given the ticket will be made avalable from {from} until {till}', 
                null, 
                [
                    'from' => $availableFrom->Nice(),
                    'till' => $eventStart->Nice(),
                ]
            ));
        }

        return $fields;
    }

    public function getTypeField()
    {
        $ticketTypes = ClassInfo::subclassesFor(Buyable::class);
        $ticketTypes = array_combine($ticketTypes, $ticketTypes);
        $ticketTypes = array_map(fn($class) => singleton($class)->i18n_singular_name(), $ticketTypes);
        $sold = OrderItem::get()->filter(['BuyableID' => $this->ID])->count();
        if ($sold > 0) {
            return ReadonlyField::create(
                'Type', 
                _t(__CLASS__ . '.Type', 'Type'),
                $this->i18n_singular_name()
            )->setDescription('Er zijn al tickets van dit type verkocht, deze kan niet meer aangepast worden.');
        } else {
            return DropdownField::create(
                'ClassName', 
                _t(__CLASS__ . '.Type', 'Type'),
                $ticketTypes,
            );
        }
    }

    /**
     * Returns the singular name without the namespaces
     *
     * @return string
     */
    public function singular_name()
    {
        $name = explode('\\', parent::singular_name());
        return trim(end($name));
    }

    /**
     * Get the available form date if it is set,
     * otherwise get it from the parent
     *
     * @return DBDate|DBField|null
     * @throws Exception
     */
    public function getAvailableFrom()
    {
        if ($this->AvailableFromDate) {
            return $this->dbObject('AvailableFromDate');
        } elseif ($startDate = $this->getEventStartDate()) {
            $lastWeek = new DBDate();
            $lastWeek->setValue(strtotime(self::config()->get('sale_start_threshold'), strtotime($startDate->value)));
            return $lastWeek;
        }

        return null;
    }

    /**
     * Get the available till date if it is set,
     * otherwise get it from the parent
     * Use the event start date as last sale possibility
     *
     * @return DBDatetime|DBField|null
     * @throws Exception
     */
    public function getAvailableTill()
    {
        if ($this->AvailableTillDate) {
            return $this->dbObject('AvailableTillDate');
        } elseif ($startDate = $this->getEventStartDate()) {
            $till = strtotime(self::config()->get('sale_end_threshold'), strtotime($startDate->getValue()));
            $date = DBDatetime::create();
            $date->setValue(date('Y-m-d H:i:s', $till));
            return $date;
        }


        return null;
    }

    /**
     * Validate if the start and end date are in the past and the future
     *
     * @return bool
     * @throws Exception
     */
    public function validateDate()
    {
        if (
            ($from = $this->getAvailableFrom()) &&
            ($till = $this->getAvailableTill()) &&
            $from->InPast() &&
            $till->InFuture()
        ) {
            return true;
        }

        return false;
    }

    /**
     * Implement on subclass
     *
     * @return bool
     */
    protected function validateAvailability()
    {
        if ($this->Capacity !== 0) {
            return $this->getAvailability() > 0;
        }
     
        return true;
    }

    public function getSoldStatus()
    {
        $sold = $this->getSoldAmount();
        if (!$capacity = $this->Capacity) {
            $capacity = $this->TicketPage()->getCapacity();
        }
        
        return "{$sold}/{$capacity}";
    }

    public function getSoldAmount()
    {
        $orderItems = OrderItem::get()->filter([
            'Reservation.Status' => [
                Reservation::STATUS_PAID,
            ],
            'BuyableID' => $this->ID
        ]);
        $count = 0;
        if ($orderItems->exists()) {
            foreach ($orderItems as $orderItem) {
                $reservation = $orderItem->Reservation();
                if( $reservation->exists() ) {
                    $attendees = $reservation->Attendees()->filter(['TicketStatus' => 'Active']);
                    $count += $attendees->Count();
                }
            }
        }
        return $count;
    }

    public function getReservedAmount()
    {
        return OrderItem::get()->filter([
            'Reservation.Status' => [
                Reservation::STATUS_PAID,
                Reservation::STATUS_CART,
                Reservation::STATUS_PENDING,
            ],
            'BuyableID' => $this->ID
        ])->sum('Amount');
    }

    /**
     * Get the ticket availability for this type
     * A buyable always checks own capacity before event capacity
     */
    public function getAvailability()
    {
        $capacity = $this->Capacity;
        if ($capacity !== 0) {
            $reserved = $this->getReservedAmount();
            return max($capacity - $reserved, 0);
        }

        // fallback to page availability if capacity is not set
        return $this->TicketPage()->getAvailability();
    }

    /**
     * Return if the ticket is available or not
     *
     * @return bool
     * @throws Exception
     */
    public function getAvailable()
    {
        if (!$this->IsAvailable) {
            return false;
        }

        if (!$this->getAvailableFrom() && !$this->getAvailableTill()) {
            return false;
        } elseif ($this->validateDate() && $this->validateAvailability()) {
            return true;
        }

        return false;
    }

    /**
     * Return availability for use in grid fields
     *
     * @return LiteralField
     * @throws Exception
     */
    public function getAvailableSummary()
    {
        $available = $this->getAvailable()
            ? '<span style="color: #3adb76;">' . _t(__CLASS__ . '.Available', 'Tickets available') . '</span>'
            : '<span style="color: #cc4b37;">' . _t(__CLASS__ . '.Unavailable', 'Not for sale') . '</span>';

        return new LiteralField('Available', $available);
    }

    /**
     * Get the event start date
     *
     * @return DBDatetime|null
     * @throws Exception
     */
    private function getEventStartDate()
    {
        $startDate = $this->TicketPage()->getEventStartDate();
        $this->extend('updateEventStartDate', $startDate);
        return $startDate;
    }

    public function createsAttendees()
    {
        return false;
    }

    /**
     * A buyable doesn't create attendees
     * @see Ticket::createAttendees()
     */
    public function createAttendees($amount)
    {
        return [];
    }
}
