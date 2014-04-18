<?php

namespace Oro\Bundle\DashboardBundle\Entity\Repository;

use Doctrine\ORM\EntityRepository;

use Oro\Bundle\DashboardBundle\Entity\Dashboard;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;

class DashboardRepository extends EntityRepository
{
    /**
     * @var AclHelper
     */
    protected $aclHelper;

    /**
     * @param AclHelper $aclHelper
     */
    public function setAclHelper(AclHelper $aclHelper)
    {
        $this->aclHelper = $aclHelper;
    }

    /**
     * @return Dashboard[]
     */
    public function getAvailableDashboards()
    {
        $qb = $this->createQueryBuilder('d');
        return $this->aclHelper->apply($qb)->execute();
    }

    /**
     * @param integer $id
     * @return Dashboard|null
     */
    public function getAvailableDashboard($id)
    {
        $qb = $this->createQueryBuilder('d')->where('d.id=:id');
        return $this->aclHelper->apply($qb)->setParameters(array('id' => $id))->getOneOrNullResult();
    }
}