import apiFetch from '../../utils/api-fetch';

export function associationRequest( { containerId, hierarchyResolver, value }, search ) {
	return apiFetch(
		window.wpApiSettings.root + 'carbon-fields/v1/association/' + ( search ? 'options' : '' ),
		'get',
		{
			container_id: containerId,
			field_id: hierarchyResolver,
			options: value.map( ( item ) => [ item.id, item.type, item.subtype ].join( ':' ) ).join( ';' ),
			...search
		}
	);
}
