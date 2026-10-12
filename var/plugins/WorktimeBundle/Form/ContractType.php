<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Form;

use App\Form\Type\DatePickerType;
use KimaiPlugin\WorktimeBundle\Calculator\HolidayCalendar;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Hours are entered/stored as seconds; UI mapping to hours is done in the template/controller for MVP.
 */
class ContractType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $days = ['Monday' => 'Montag', 'Tuesday' => 'Dienstag', 'Wednesday' => 'Mittwoch', 'Thursday' => 'Donnerstag', 'Friday' => 'Freitag', 'Saturday' => 'Samstag', 'Sunday' => 'Sonntag'];
        foreach ($days as $day => $label) {
            $builder->add('workHours'.$day, NumberType::class, [
                'label' => $label.' (Std.)',
                'mapped' => false,
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.25', 'min' => 0, 'max' => 24],
                'constraints' => [new Assert\Range(min: 0, max: 24)],
            ]);
        }

        $builder
            ->add('holidayRegion', ChoiceType::class, [
                'label' => 'Bundesland (Feiertage)',
                'choices' => array_flip(HolidayCalendar::REGIONS),
                'help' => 'Gesetzliche Feiertage dieses Bundeslandes setzen das Soll auf 0.',
            ])
            ->add('holidaysPerYear', NumberType::class, ['label' => 'Urlaubstage pro Jahr', 'required' => false, 'empty_data' => '0', 'scale' => 1, 'constraints' => [new Assert\Range(min: 0, max: 366)]])
            ->add('vacationCarryover', NumberType::class, ['label' => 'Resturlaub aus dem Vorjahr (Übertrag ins erste Jahr)', 'required' => false, 'empty_data' => '0', 'scale' => 1, 'constraints' => [new Assert\Range(min: -366, max: 366)]])
            ->add('initialOvertimeHours', NumberType::class, [
                'label' => 'Startsaldo Überstunden (Std.)',
                'help' => 'Übernommener Stand zu Beschäftigungsbeginn, negativ = Minusstunden.',
                'mapped' => false,
                'required' => false,
                'scale' => 2,
                'constraints' => [new Assert\Range(min: -1000, max: 1000)],
            ])
            ->add('employmentStart', DatePickerType::class, ['label' => 'Beschäftigungsbeginn', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('accountStart', DatePickerType::class, ['label' => 'Zeitkonto ab', 'help' => 'Stichtag: Tage davor erzeugen keine Plus-/Minusstunden.', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('employmentEnd', DatePickerType::class, ['label' => 'Beschäftigungsende', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('dailyEndTime', TextType::class, [
                'label' => 'Automatisches Ausstempeln um',
                'help' => 'Vergessene Stempelungen werden nachts auf diese Uhrzeit geschlossen und zur Prüfung markiert (leer = 17:00).',
                'required' => false,
                'attr' => ['placeholder' => '17:00', 'inputmode' => 'numeric', 'maxlength' => 5],
                'constraints' => [new Assert\Regex(pattern: '/^([01]?\d|2[0-3]):[0-5]\d$/', message: 'Bitte als Uhrzeit HH:MM angeben, z. B. 17:30.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Contract::class]);
    }
}
