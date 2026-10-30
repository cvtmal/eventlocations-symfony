<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\ValueObject\AdminRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ChangeAdminRoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('role', EnumType::class, [
            'class' => AdminRole::class,
            'label' => 'users.form.role',
            'choice_label' => static fn (AdminRole $role) => $role->label(),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChangeAdminRoleData::class, 'translation_domain' => 'admin']);
    }
}
