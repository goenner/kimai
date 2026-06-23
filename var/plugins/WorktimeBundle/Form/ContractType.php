<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Form;

use KimaiPlugin\WorktimeBundle\Entity\Contract;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Hours are entered/stored as seconds; UI mapping to hours is done in the template/controller for MVP.
 */
class ContractType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
            $builder->add('workHours' . $day, IntegerType::class, [
                'label' => $day,
                'mapped' => false,
                'required' => false,
            ]);
        }

        $builder
            ->add('holidaysPerYear', NumberType::class, ['label' => 'Urlaubstage/Jahr', 'required' => false])
            ->add('vacationCarryover', NumberType::class, ['label' => 'Urlaubsübertrag', 'required' => false])
            ->add('employmentStart', DateType::class, ['label' => 'Beschäftigungsbeginn', 'widget' => 'single_text', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('employmentEnd', DateType::class, ['label' => 'Beschäftigungsende', 'widget' => 'single_text', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('holidayRegion', TextType::class, ['label' => 'Feiertagsregion', 'required' => false])
            ->add('dailyEndTime', TextType::class, ['label' => 'Tägliche Endzeit (HH:MM)', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Contract::class]);
    }
}
