import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as dispatchApi from '../../api/dispatch';

export function useDispatches(status?: dispatchApi.DispatchStatus) {
  return useQuery({
    queryKey: ['dispatches', status ?? 'all'],
    queryFn: () => dispatchApi.listDispatches(status ? { status } : {}),
  });
}

export function useDispatch(id: number) {
  return useQuery({ queryKey: ['dispatch', id], queryFn: () => dispatchApi.getDispatch(id) });
}

export function useCompleteDispatch(id: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (actualDeliveryDate?: string) => dispatchApi.completeDispatch(id, actualDeliveryDate),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['dispatch', id] });
      queryClient.invalidateQueries({ queryKey: ['dispatches'] });
    },
  });
}
