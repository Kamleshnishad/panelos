import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as dispatchApi from '../../api/dispatch';

export function useDispatches(status?: dispatchApi.DispatchStatus) {
  return useInfiniteQuery({
    queryKey: ['dispatches', status ?? 'all'],
    queryFn: ({ pageParam }) => dispatchApi.listDispatches(status ? { status, page: pageParam } : { page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (lastPage) => (lastPage.current_page * lastPage.per_page < lastPage.total ? lastPage.current_page + 1 : undefined),
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
